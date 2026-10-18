<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when Crossref is unreachable or returned an unexpected server error
 * after bounded retries.
 *
 * Fatal for the document when no Crossref request has succeeded yet in the run
 * (OQ-03: a total outage must fail the document with a safe message); otherwise
 * the affected reference degrades to `pending`. The raw exception goes to the
 * log; the safe user-facing literal is owned by the analysis failure handler
 * (`docs/SECURITY.md` §3).
 */
final class CrossrefUnavailableException extends RuntimeException
{
    /**
     * Connection error, timeout or 5xx after retries.
     */
    public static function serviceUnavailable(?Throwable $previous = null): self
    {
        return new self('Crossref could not be reached.', previous: $previous);
    }

    /**
     * A response Crossref should not return for this endpoint (e.g. a non-404 4xx).
     */
    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self
    {
        return new self("Crossref responded with an unexpected HTTP status {$status}.", previous: $previous);
    }
}
