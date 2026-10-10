<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A fatal report-rendering failure: Gotenberg is unreachable, returned a
 * non-2xx status or produced a malformed PDF.
 *
 * Not an `ApiException`: report generation runs asynchronously, so there is no
 * synchronous HTTP response to render. `ReportFailureHandler` maps it to a safe
 * `generated_document_reports.error` while the raw exception only reaches the log
 * (`docs/SECURITY.md` §10).
 */
final class ReportRenderingException extends RuntimeException
{
    /**
     * Gotenberg could not be reached (connection error or timeout).
     */
    public static function serviceUnavailable(?Throwable $previous = null): self
    {
        return new self('Gotenberg could not be reached.', previous: $previous);
    }

    /**
     * Gotenberg answered with a non-2xx status.
     */
    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self
    {
        return new self("Gotenberg responded with an unexpected HTTP status {$status}.", previous: $previous);
    }

    /**
     * Gotenberg returned an empty body or something that is not a PDF.
     */
    public static function malformedResponse(?Throwable $previous = null): self
    {
        return new self('Gotenberg returned a malformed PDF response.', previous: $previous);
    }
}
