<?php

use App\Data\Error\ErrorResponseData;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only backend: never redirect guests to a web login route.
        // Unauthenticated API requests render the canonical 401 envelope instead.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldRenderAsApiError = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($shouldRenderAsApiError);

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($shouldRenderAsApiError): ?JsonResponse {
            if (! $shouldRenderAsApiError($request)) {
                return null;
            }

            return response()->json([
                'error' => new ErrorResponseData(
                    code: 'UNAUTHENTICATED',
                    message: 'Unauthenticated.',
                ),
            ], 401);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($shouldRenderAsApiError): ?JsonResponse {
            if (! $shouldRenderAsApiError($request)) {
                return null;
            }

            return response()->json([
                'error' => new ErrorResponseData(
                    code: 'VALIDATION_ERROR',
                    message: 'The given data was invalid.',
                    details: $exception->errors(),
                ),
            ], 422);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) use ($shouldRenderAsApiError): ?JsonResponse {
            if (! $shouldRenderAsApiError($request)) {
                return null;
            }

            return response()->json([
                'error' => new ErrorResponseData(
                    code: 'RATE_LIMITED',
                    message: 'Too many attempts. Please try again later.',
                ),
            ], 429, $exception->getHeaders());
        });
    })->create();
