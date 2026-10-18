<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A report cannot be generated for the resolved document.
 *
 * Internal, never rendered: report generation is asynchronous, so the job maps
 * it through `ReportFailureHandler` to a safe `generated_document_reports.error`.
 * The only case today is the defensive guard against a non-completed document
 * (reports are requested only for completed documents).
 */
final class ReportGenerationFailedException extends RuntimeException
{
    public static function documentNotCompleted(): self
    {
        return new self('The document is not in a completed state.');
    }
}
