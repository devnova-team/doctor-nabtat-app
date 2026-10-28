<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            if (in_array('optional', $options, true)) {
                return $next($request);
            }

            if (Auth::check()) {
                return $next($request);
            }

            throw new AuthenticationException('Unauthenticated.');
        }

        $claims = app(JwtTokenService::class)->decode($token);
        if (
            $claims === null
            || Cache::has('auth:revoked-jti:'.$claims['jti'])
            || (isset($claims['sid']) && Cache::has('auth:revoked-family:'.$claims['sid']))
        ) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $userId = (string) $claims['sub'];
        $expectedVersion = Cache::get('auth:user-version:'.$userId, $claims['ver'] ?? null);
        if (
            ! ($claims['is_active'] ?? false)
            || Cache::has('auth:revoked-user:'.$userId)
            || (int) $expectedVersion !== (int) ($claims['ver'] ?? -1)
        ) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (! isset($claims['clinic_id'], $claims['role'], $claims['email'], $claims['name'])) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $user = (new User)->newFromBuilder([
            'id' => $userId,
            'clinic_id' => $claims['clinic_id'],
            'name' => $claims['name'],
            'email' => $claims['email'],
            'phone' => $claims['phone'] ?? null,
            'specialty' => $claims['specialty'] ?? null,
            'role' => $claims['role'],
            'is_active' => true,
            'mfa_enabled' => $claims['mfa_enabled'] ?? false,
            'token_version' => $claims['ver'],
        ]);
        $request->attributes->set('jwt_claims', $claims);
        $request->setUserResolver(fn (): User => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
