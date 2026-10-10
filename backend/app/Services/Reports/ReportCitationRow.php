<?php

namespace App\Services\Reports;

use App\Enums\CitationStatus;

/**
 * One citation issue as rendered in the report.
 *
 * `referenceLabel` is the paired reference's title, falling back to its raw
 * text; `message` comes from {@see CitationStatus::message()} (D-06-05).
 */
final readonly class ReportCitationRow
{
    public function __construct(
        public ?string $citationText,
        public CitationStatus $status,
        public ?string $referenceLabel,
        public string $message,
    ) {}
}
