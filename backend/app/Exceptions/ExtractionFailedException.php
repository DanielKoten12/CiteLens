<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A fatal extraction failure: the stored PDF is unreadable/unparseable or the
 * internal inference service returned a malformed payload.
 *
 * Never rendered to the public API. The analysis failure handler maps it to a
 * safe, user-facing `analysis_error` while the raw exception goes to the log only
 * (`docs/SECURITY.md` §3). Passing the previous exception preserves the trace in
 * logs without exposing it in the response.
 */
final class ExtractionFailedException extends RuntimeException
{
    /**
     * The document has no stored `files` row.
     */
    public static function fileMissing(): self
    {
        return new self('The document has no stored file.');
    }

    /**
     * The stored object could not be opened for reading.
     */
    public static function fileUnreadable(): self
    {
        return new self('The stored document file could not be read.');
    }

    /**
     * The inference service rejected the PDF (`/v1/extract` 4xx).
     */
    public static function invalidPdf(?Throwable $previous = null): self
    {
        return new self('The inference service could not process the PDF.', previous: $previous);
    }

    /**
     * The inference service returned a body that does not match the contract.
     */
    public static function malformedPayload(?Throwable $previous = null): self
    {
        return new self('The inference service returned a malformed payload.', previous: $previous);
    }

    /**
     * An extracted string exceeds the storage capacity configured for the column.
     */
    public static function payloadTooLarge(string $field): self
    {
        return new self("The extracted {$field} exceeds the maximum storable length.");
    }
}
