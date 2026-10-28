<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseEnvelope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'application/json')) {
            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);
        if (! is_array($body) || array_key_exists('success', $body)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $errors = $body['errors'] ?? [];
            if (is_array($errors) && ! array_is_list($errors)) {
                $errors = collect($errors)->flatMap(fn (array|string $messages, string $field): array => array_map(
                    fn (string $message): array => ['field' => $field, 'message' => $message],
                    (array) $messages,
                ))->values()->all();
            }

            $payload = [
                'success' => false,
                'statusCode' => $status,
                'message' => $body['message'] ?? 'The request could not be completed.',
            ];
            if ($errors !== []) {
                $payload['errors'] = $errors;
            }
        } else {
            $payload = [
                'success' => true,
                'statusCode' => $status,
                'message' => $body['message'] ?? 'Request successful.',
                'data' => $body['data'] ?? $body,
                'meta' => [
                    ...($body['meta'] ?? []),
                    ...(isset($body['links']) ? ['links' => $body['links']] : []),
                    'timestamp' => $body['meta']['timestamp'] ?? now()->toISOString(),
                ],
            ];
        }

        if ($response instanceof JsonResponse) {
            $response->setData($payload);
        } else {
            $response->setContent(json_encode($payload, JSON_THROW_ON_ERROR));
        }

        return $response;
    }
}
