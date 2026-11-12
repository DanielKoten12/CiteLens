<?php

namespace App\Exceptions;

use App\Contracts\Metadata\SourceUnavailableException;
use App\Enums\MetadataSource;
use RuntimeException;
use Throwable;

/**
 * Raised when Crossref is unreachable or returned an unexpected server error
 * after bounded retries.
 *
 * Policy: fatal for the document when no Crossref request has succeeded yet in
 * the run (OQ-03: a total outage must fail the document with a safe message);
 * otherwise the affected reference is recorded as a transient failure and the
 * metadata chains continue with the remaining sources. The raw exception goes
 * to the log; the safe user-facing literal is owned by the analysis failure
 * handler (`docs/SECURITY.md` §3).
 */
final class CrossrefUnavailableException extends RuntimeException implements SourceUnavailableException
{
    private string $reason = 'service unavailable';

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
        $exception = new self("Crossref responded with an unexpected HTTP status {$status}.", previous: $previous);
        $exception->reason = "unexpected HTTP status {$status}";

        return $exception;
    }

    public function source(): MetadataSource
    {
        return MetadataSource::Crossref;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
