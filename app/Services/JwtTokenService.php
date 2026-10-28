<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class JwtTokenService
{
    /**
     * @return array{token: string, claims: array<string, mixed>}
     */
    public function issue(User $user, int $ttlSeconds = 120, ?string $familyId = null): array
    {
        $now = now()->timestamp;
        $claims = [
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'specialty' => $user->specialty,
            'role' => $user->role,
            'permissions' => $this->permissionsFor($user->role),
            'clinic_id' => $user->clinic_id,
            'is_active' => $user->is_active,
            'mfa_enabled' => $user->mfa_enabled,
            'sid' => $familyId,
            'ver' => (int) $user->token_version,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->signingKey(), true));

        return ['token' => $header.'.'.$payload.'.'.$signature, 'claims' => $claims];
    }

    /** @return array<string, mixed>|null */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->signingKey(), true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        $claims = json_decode($this->base64UrlDecode($payload), true);
        if (! is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256' || ! is_array($claims)) {
            return null;
        }

        if (! isset($claims['sub'], $claims['jti'], $claims['exp']) || (int) $claims['exp'] <= now()->timestamp) {
            return null;
        }

        return $claims;
    }

    /** @return array<string> */
    private function permissionsFor(string $role): array
    {
        return match ($role) {
            'owner' => ['*'],
            'admin' => ['staff:manage', 'patients:read', 'patients:write', 'appointments:manage', 'billing:manage'],
            'doctor' => ['patients:read', 'appointments:read', 'clinical:write'],
            'receptionist' => ['patients:read', 'patients:write', 'appointments:manage', 'billing:manage'],
            default => [],
        };
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
