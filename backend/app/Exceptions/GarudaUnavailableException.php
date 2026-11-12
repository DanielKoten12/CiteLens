<?php

namespace App\Exceptions;

use App\Contracts\Metadata\SourceUnavailableException;
use App\Enums\MetadataSource;
use RuntimeException;
use Throwable;

/**
 * Raised when Garuda is unreachable, returns an unexpected response, or serves
 * a page the parser no longer understands.
 *
 * Policy (`docs/plans/backend/08-garuda-fallback-source.md`, D-08-03): the first
 * failure of an analysis run fails the document by default; a failure after one
 * completed Garuda exchange only marks that reference as a transient failure. A
 * page that explicitly reports `Found 0 documents` is a **result**, not an
 * exception. The safe user-facing literal is owned by the analysis failure
 * handler (`docs/SECURITY.md` §3).
 */
final class GarudaUnavailableException extends RuntimeException implements SourceUnavailableException
{
    private string $reason = 'service unavailable';

    /**
     * Connection error, timeout or 5xx after retries.
     */
    public static function serviceUnavailable(?Throwable $previous = null): self
    {
        return new self('Garuda could not be reached.', previous: $previous);
    }

    /**
     * A response Garuda should not return for the search endpoint.
     */
    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self
    {
        $exception = new self("Garuda responded with an unexpected HTTP status {$status}.", previous: $previous);
        $exception->reason = "unexpected HTTP status {$status}";

        return $exception;
    }

    /**
     * The response is not the search page we know how to parse.
     */
    public static function unexpectedFormat(string $reason): self
    {
        $exception = new self("Garuda returned an unexpected page: {$reason}");
        $exception->reason = 'unexpected response format';

        return $exception;
    }

    public function source(): MetadataSource
    {
        return MetadataSource::Garuda;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
