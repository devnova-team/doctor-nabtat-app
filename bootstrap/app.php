<?php

use App\Http\Middleware\ApiResponseEnvelope;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureDoctorRole;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [ApiResponseEnvelope::class]);
        $middleware->alias([
            'doctor' => EnsureDoctorRole::class,
            'jwt.auth' => AuthenticateApiToken::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'statusCode' => 401,
                'message' => 'Unauthenticated.',
            ], 401);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'statusCode' => 403,
                'message' => $exception->getMessage() ?: 'You do not have permission to perform this action.',
            ], 403);
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'statusCode' => 422,
                'message' => 'Validation failed for the submitted data.',
                'errors' => collect($exception->errors())
                    ->flatMap(fn (array $messages, string $field): array => array_map(
                        fn (string $message): array => ['field' => $field, 'message' => $message],
                        $messages,
                    ))
                    ->values(),
            ], 422);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') || ! in_array($exception->getStatusCode(), [401, 403], true)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'statusCode' => $exception->getStatusCode(),
                'message' => $exception->getStatusCode() === 401 ? 'Unauthenticated.' : 'You do not have permission to perform this action.',
            ], $exception->getStatusCode());
        });
    })->create();
