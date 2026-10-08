<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An unexpected response from the internal inference service that is not a
 * service-availability problem (e.g. a 4xx on `/v1/embeddings`, or a body that
 * violates the contract in a way `ExtractionFailedException` does not cover).
 *
 * Fatal for the document: it indicates a backend/contract bug, not a degraded
 * external dependency. The raw details go to the log only.
 */
final class InferenceClientException extends RuntimeException
{
    public static function unexpectedResponse(int $status): self
    {
        return new self("The inference service responded with an unexpected HTTP status {$status}.");
    }

    public static function malformedPayload(?Throwable $previous = null): self
    {
        return new self('The inference service returned a malformed payload.', previous: $previous);
    }
}
