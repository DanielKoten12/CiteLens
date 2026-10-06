<?php

namespace App\Exceptions;

use App\Http\Responses\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Registers the framework-level exception renderers for the canonical error
 * envelope (`docs/API_SPEC.md` §2.5).
 *
 * Domain exceptions render themselves via {@see ApiException}; this class only
 * covers framework/driver exceptions.
 *
 * Registration order is part of the contract: Laravel's `Handler::renderViaCallbacks()`
 * walks the renderers in insertion order, so specific types must be registered before
 * the `HttpExceptionInterface` and `Throwable` fallbacks.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(self::shouldRenderAsApiError(...));

        $exceptions->render(self::authentication(...));
        $exceptions->render(self::validation(...));
        $exceptions->render(self::throttle(...));
        $exceptions->render(self::notFound(...));
        $exceptions->render(self::authorization(...));
        $exceptions->render(self::methodNotAllowed(...));
        $exceptions->render(self::httpException(...));
        $exceptions->render(self::unexpected(...));
    }

    /**
     * API requests (and explicit JSON requests) never get redirects or HTML errors.
     */
    private static function shouldRenderAsApiError(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private static function authentication(AuthenticationException $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::response('UNAUTHENTICATED', 'Unauthenticated.', 401)
            : null;
    }

    private static function validation(ValidationException $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::response('VALIDATION_ERROR', 'The given data was invalid.', 422, $exception->errors())
            : null;
    }

    private static function throttle(ThrottleRequestsException $exception, Request $request): ?JsonResponse
    {
        if (! self::shouldRenderAsApiError($request)) {
            return null;
        }

        return ApiError::response(
            code: 'RATE_LIMITED',
            message: 'Too many attempts. Please try again later.',
            status: 429,
            headers: $exception->getHeaders(),
        );
    }

    /**
     * Unmatched routes and `ModelNotFoundException` (converted by the handler).
     */
    private static function notFound(NotFoundHttpException $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::statusResponse(404)
            : null;
    }

    /**
     * Reserved for genuine policy denials; foreign resources must never reach this
     * and must return 404 instead (`docs/SECURITY.md` §2.1).
     */
    private static function authorization(AuthorizationException|AccessDeniedHttpException $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::statusResponse(403)
            : null;
    }

    /**
     * The canonical table has no 405; method mismatches are malformed requests.
     */
    private static function methodNotAllowed(MethodNotAllowedHttpException $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::response('BAD_REQUEST', 'Metode permintaan tidak diizinkan.', 400)
            : null;
    }

    /**
     * Fallback for HTTP exceptions without a dedicated renderer (e.g. `abort(418)`).
     * The exception message is never echoed; only canonical safe messages are used.
     */
    private static function httpException(HttpExceptionInterface $exception, Request $request): ?JsonResponse
    {
        return self::shouldRenderAsApiError($request)
            ? ApiError::statusResponse($exception->getStatusCode())
            : null;
    }

    /**
     * Last-resort catch-all: never leak internals, never swallow a prepared response.
     */
    private static function unexpected(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::shouldRenderAsApiError($request)) {
            return null;
        }

        // `HttpResponseException` carries an already-prepared response (e.g. the
        // 413/415 envelopes built by UploadDocumentRequest::failedValidation()).
        // Return null so the framework returns that response instead of a 500.
        if ($exception instanceof HttpResponseException) {
            return null;
        }

        return ApiError::response('SERVER_ERROR', 'Terjadi kesalahan pada server.', 500);
    }
}
