<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'If an account exists for that email, password reset instructions have been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, AuditLogService $audit, AuthService $auth): JsonResponse
    {
        $data = $request->validated();
        $status = Password::reset($data, function (User $user, string $password) use ($request, $audit, $auth): void {
            DB::transaction(function () use ($user, $password, $request, $audit, $auth): void {
                $user->forceFill([
                    'password' => $password,
                    'token_version' => $user->token_version + 1,
                    'remember_token' => Str::random(60),
                ])->save();
                Cache::put('auth:user-version:'.$user->id, (int) $user->token_version, now()->addDays(30));
                Cache::put('auth:revoked-user:'.$user->id, true, now()->addDays(30));

                $auth->revokeUserSessions($user->id);

                $audit->record($user, 'auth.password.reset', 'critical', $request);
            });

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'statusCode' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'message' => __($status),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'statusCode' => JsonResponse::HTTP_OK,
            'message' => 'Password reset successfully. Please log in again.',
        ]);
    }
}
