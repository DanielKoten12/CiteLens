<?php

namespace App\Exceptions;

use App\Http\Responses\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base class for domain exceptions that render the canonical API error envelope
 * (`docs/API_SPEC.md` §2.5).
 *
 * Rendering lives beside the exception so services and controllers stay free of
 * transport concerns while the envelope shape stays in {@see ApiError}.
 */
abstract class ApiException extends RuntimeException
{
    /**
     * The canonical error code (`docs/API_SPEC.md` §2.5).
     */
    abstract public function code(): string;

    /**
     * The HTTP status for this exception.
     */
    abstract public function status(): int;

    /**
     * Optional machine-readable details for the `error.details` object.
     *
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return null;
    }

    /**
     * Render the exception using the canonical API error envelope.
     */
    public function render(Request $request): JsonResponse
    {
        return ApiError::response(
            code: $this->code(),
            message: $this->getMessage(),
            status: $this->status(),
            details: $this->details(),
        );
    }
}
