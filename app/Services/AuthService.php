<?php

namespace App\Services;

use App\Models\LoginHistory;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private readonly JwtTokenService $jwt) {}

    /** @return array{allowed: bool, remaining: int, retry_after: int} */
    public function consumeLoginAttempt(string $ipAddress): array
    {
        $key = 'auth:login-window:'.hash('sha256', $ipAddress);

        return Cache::lock($key.':lock', 5)->block(2, function () use ($key): array {
            $now = now()->timestamp;
            $attempts = array_values(array_filter(
                Cache::get($key, []),
                fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - 60,
            ));

            if (count($attempts) >= 5) {
                $retryAfter = max(1, $attempts[0] + 60 - $now);
                Cache::put($key, $attempts, now()->addSeconds($retryAfter));

                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
            }

            $attempts[] = $now;
            Cache::put($key, $attempts, now()->addSeconds(60));

            return ['allowed' => true, 'remaining' => max(0, 5 - count($attempts)), 'retry_after' => 0];
        });
    }

    public function recordLogin(?User $user, string $email, string $status, Request $request): void
    {
        LoginHistory::query()->create([
            'user_id' => $user?->id,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => $status,
            'logged_in_at' => now(),
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, family_id: string}
     */
    public function issueTokenPair(User $user, Request $request, ?string $familyId = null): array
    {
        $familyId ??= (string) Str::uuid();
        $access = $this->jwt->issue($user, familyId: $familyId);
        $refreshToken = bin2hex(random_bytes(32));
        Cache::put('auth:user-version:'.$user->id, (int) $user->token_version, now()->addDays(30));
        Cache::forget('auth:revoked-user:'.$user->id);

        RefreshToken::query()->create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(30),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 120,
            'family_id' => $familyId,
        ];
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Cache::put('auth:revoked-family:'.$familyId, true, now()->addDays(30));
    }

    public function revokeUserSessions(int $userId): void
    {
        $familyIds = RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('family_id');

        foreach ($familyIds as $familyId) {
            $this->revokeFamily((string) $familyId);
        }
    }

    public function revokeAccessToken(string $jti, int $expiresAt): void
    {
        Cache::put('auth:revoked-jti:'.$jti, true, now()->setTimestamp($expiresAt));
    }
}
