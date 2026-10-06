<?php

namespace App\Enums;

/**
 * Status of an on-demand generated PDF report
 * (`generated_document_reports.status`).
 *
 * Canonical values: `docs/API_SPEC.md` §2.6.
 */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the report can no longer change state on its own.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Processing => false,
        };
    }
}
