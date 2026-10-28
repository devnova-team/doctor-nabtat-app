<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RefreshTokenRequest;
use App\Http\Requests\Api\Auth\RegisterClinicRequest;
use App\Http\Requests\Api\Auth\VerifyMfaRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\Clinic;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(RegisterClinicRequest $request): JsonResponse
    {
        $data = $request->validated();
        $totp = app(TotpService::class);
        [$clinic, $user, $secret] = DB::transaction(function () use ($data, $totp): array {
            $clinic = Clinic::query()->create([
                'name' => $data['clinic_name'],
                'phone' => $data['clinic_phone'] ?? null,
            ]);
            $user = $clinic->users()->create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'owner',
                'is_active' => true,
            ]);
            $secret = $totp->generateSecret();
            $user->forceFill(['mfa_secret' => $secret])->save();

            return [$clinic, $user, $secret];
        });

        $challenge = Str::random(64);
        Cache::put('auth:mfa-challenge:'.hash('sha256', $challenge), [
            'user_id' => $user->id,
            'purpose' => 'setup',
        ], now()->addMinutes(10));

        app(AuditLogService::class)->record($user, 'clinic.registered', 'info', $request);

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_CREATED,
            'message' => 'Clinic account created. Complete MFA setup to receive an authenticated session.',
            'data' => [
                'clinic' => ['id' => $clinic->id, 'name' => $clinic->name],
                'user' => new UserResource($user),
                'mfa_required' => true,
                'challenge_token' => $challenge,
                'expires_in' => 600,
                'secret' => $secret,
                'qr_code_uri' => $totp->provisioningUri($user->email, $secret, (string) config('app.name')),
            ],
            'meta' => ['timestamp' => now()->toISOString()],
        ], JsonResponse::HTTP_CREATED);
    }

    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        $data = $request->validated();
        $limit = $auth->consumeLoginAttempt((string) $request->ip());
        $user = User::query()->where('email', $data['email'])->first();

        if (! $limit['allowed']) {
            $auth->recordLogin($user, $data['email'], 'rate_limited', $request);

            return response()->json([
                'success' => false,
                'statusCode' => JsonResponse::HTTP_TOO_MANY_REQUESTS,
                'message' => 'Too many login attempts. Try again later.',
            ], JsonResponse::HTTP_TOO_MANY_REQUESTS, [
                'X-RateLimit-Limit' => '5',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $limit['retry_after'],
                'Retry-After' => (string) $limit['retry_after'],
            ]);
        }

        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            $auth->recordLogin($user, $data['email'], 'failed_password', $request);
            app(AuditLogService::class)->record($user, 'auth.login.failed', 'warning', $request, ['email' => $data['email']]);

            return response()->json([
                'success' => false,
                'statusCode' => JsonResponse::HTTP_UNAUTHORIZED,
                'message' => 'The supplied credentials are invalid.',
            ], JsonResponse::HTTP_UNAUTHORIZED, [
                'X-RateLimit-Limit' => '5',
                'X-RateLimit-Remaining' => (string) $limit['remaining'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $auth->recordLogin($user, $data['email'], 'success', $request);
        app(AuditLogService::class)->record($user, 'auth.login.success', 'info', $request);

        if ($user->role === 'owner') {
            $purpose = $user->mfa_enabled ? 'login' : 'setup';
            if (! $user->mfa_secret) {
                $user->forceFill(['mfa_secret' => app(TotpService::class)->generateSecret()])->save();
            }
            $challenge = Str::random(64);
            Cache::put('auth:mfa-challenge:'.hash('sha256', $challenge), [
                'user_id' => $user->id,
                'purpose' => $purpose,
            ], now()->addMinutes(5));

            $mfaData = ['mfa_required' => true, 'challenge_token' => $challenge, 'expires_in' => 300];
            if ($purpose === 'setup') {
                $mfaData['secret'] = $user->mfa_secret;
                $mfaData['qr_code_uri'] = app(TotpService::class)->provisioningUri(
                    $user->email,
                    $user->mfa_secret,
                    (string) config('app.name'),
                );
            }

            return response()->json([
                'success' => true,
                'statusCode' => JsonResponse::HTTP_OK,
                'message' => $purpose === 'setup'
                    ? 'Set up MFA in an authenticator app, then verify a code to complete login.'
                    : 'A valid MFA code is required to complete login.',
                'data' => $mfaData,
                'meta' => ['timestamp' => now()->toISOString(), 'rateLimit' => $limit],
            ]);
        }

        $tokens = $auth->issueTokenPair($user, $request);

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'Login successful.',
            'data' => ['user' => new UserResource($user), 'tokens' => $tokens],
            'meta' => ['timestamp' => now()->toISOString(), 'rateLimit' => $limit],
        ]);
    }

    public function setupMfa(Request $request, TotpService $totp): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->role === 'owner', JsonResponse::HTTP_FORBIDDEN);

        $secret = $totp->generateSecret();
        $user->forceFill(['mfa_secret' => $secret])->save();
        app(AuditLogService::class)->record($user, 'auth.mfa.setup', 'info', $request);

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'Scan the TOTP URI in an authenticator app, then verify a code.',
            'data' => [
                'secret' => $secret,
                'qr_code_uri' => $totp->provisioningUri($user->email, $secret, (string) config('app.name')),
            ],
        ]);
    }

    public function verifyMfa(VerifyMfaRequest $request, AuthService $auth, TotpService $totp): JsonResponse
    {
        $data = $request->validated();
        if ($request->bearerToken() !== null) {
            $authenticatedUser = $request->user();
            abort_unless($authenticatedUser?->role === 'owner', JsonResponse::HTTP_FORBIDDEN);
            $user = User::query()->findOrFail($authenticatedUser->id);
        } else {
            $challengeKey = 'auth:mfa-challenge:'.hash('sha256', $data['challenge_token']);
            $challenge = Cache::get($challengeKey);
            $user = is_array($challenge) ? User::query()->find($challenge['user_id'] ?? null) : null;
            $purpose = $challenge['purpose'] ?? null;
            if (
                $user === null
                || $user->role !== 'owner'
                || ($purpose === 'login' && ! $user->mfa_enabled)
                || ($purpose === 'setup' && $user->mfa_enabled)
                || ! in_array($purpose, ['login', 'setup'], true)
            ) {
                return response()->json(['success' => false, 'statusCode' => 401, 'message' => 'The MFA challenge is invalid or expired.'], 401);
            }
        }

        if (! $user->mfa_secret || ! $totp->verify($user->mfa_secret, $data['code'])) {
            return response()->json(['success' => false, 'statusCode' => 422, 'message' => 'The MFA code is invalid.'], 422);
        }

        if (! $user->mfa_enabled) {
            $claims = $request->attributes->get('jwt_claims', []);
            if (isset($claims['jti'], $claims['exp'])) {
                $auth->revokeAccessToken($claims['jti'], (int) $claims['exp']);
            }

            $user->forceFill([
                'mfa_enabled' => true,
                'token_version' => $user->token_version + 1,
            ])->save();
            Cache::put('auth:user-version:'.$user->id, (int) $user->token_version, now()->addDays(30));
            $auth->revokeUserSessions($user->id);
        }

        if ($request->bearerToken() === null) {
            Cache::forget($challengeKey);
        }

        $auth->recordLogin($user, $user->email, 'success', $request);
        app(AuditLogService::class)->record($user, 'auth.mfa.verified', 'critical', $request);
        $tokens = $auth->issueTokenPair($user, $request);

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'MFA verified.',
            'data' => ['user' => new UserResource($user), 'tokens' => $tokens],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'Authenticated user.',
            'data' => [
                'user' => new UserResource($request->user()),
                'claims' => $request->attributes->get('jwt_claims'),
            ],
            'meta' => ['timestamp' => now()->toISOString(), 'cached' => false],
        ]);
    }

    public function refresh(RefreshTokenRequest $request, AuthService $auth): JsonResponse
    {
        $data = $request->validated();
        $result = DB::transaction(function () use ($data, $auth, $request): array {
            $token = RefreshToken::query()
                ->where('token_hash', hash('sha256', $data['refresh_token']))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return ['status' => 'invalid'];
            }

            if ($token->used_at !== null || $token->revoked_at !== null) {
                $auth->revokeFamily($token->family_id);

                return ['status' => 'reuse', 'user' => $token->user];
            }

            if ($token->expires_at->isPast()) {
                $auth->revokeFamily($token->family_id);

                return ['status' => 'expired'];
            }

            $token->forceFill(['used_at' => now()])->save();
            $user = $token->user;
            if (! $user || ! $user->is_active) {
                $auth->revokeFamily($token->family_id);

                return ['status' => 'invalid'];
            }

            return [
                'status' => 'ok',
                'user' => $user,
                'tokens' => $auth->issueTokenPair($user, $request, $token->family_id),
            ];
        }, attempts: 3);

        if ($result['status'] === 'reuse') {
            app(AuditLogService::class)->record($result['user'], 'auth.refresh.reuse_detected', 'critical', $request);
            if ($result['user'] instanceof User) {
                Mail::raw(
                    'We detected reuse of a previously rotated refresh token. Active sessions in that token family were revoked. If this was not you, reset your password.',
                    fn ($message) => $message->to($result['user']->email)->subject('Clinic account security alert'),
                );
            }

            return response()->json([
                'success' => false,
                'statusCode' => 401,
                'message' => 'Refresh token reuse detected. All sessions in this token family were revoked.',
            ], 401);
        }

        if ($result['status'] !== 'ok') {
            return response()->json(['success' => false, 'statusCode' => 401, 'message' => 'The refresh token is invalid or expired.'], 401);
        }

        return response()->json([
            'success' => true,
            'statusCode' => 200,
            'message' => 'Tokens rotated.',
            'data' => ['user' => new UserResource($result['user']), 'tokens' => $result['tokens']],
        ]);
    }

    public function logout(Request $request, AuthService $auth): JsonResponse
    {
        $user = $request->user();
        $claims = $request->attributes->get('jwt_claims', []);
        if (isset($claims['jti'], $claims['exp'])) {
            $auth->revokeAccessToken($claims['jti'], (int) $claims['exp']);
        }
        if (isset($claims['sid']) && is_string($claims['sid'])) {
            $auth->revokeFamily($claims['sid']);
        }
        if ($request->hasSession()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        app(AuditLogService::class)->record($user, 'auth.logout', 'info', $request);

        return response()->json(['success' => true, 'statusCode' => 200, 'message' => 'Logged out.']);
    }
}
