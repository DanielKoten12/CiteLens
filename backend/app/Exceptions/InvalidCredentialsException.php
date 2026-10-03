<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when login credentials do not match a user.
 *
 * An unknown email and a wrong password share this exception and its message so
 * the response cannot be used to enumerate accounts (`docs/SECURITY.md` §2).
 */
class InvalidCredentialsException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Kredensial tidak valid.');
    }

    /**
     * Render the exception using the canonical API error envelope.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $this->getMessage(),
                'details' => [
                    'email' => ['These credentials do not match our records.'],
                ],
            ],
        ], 422);
    }
}
