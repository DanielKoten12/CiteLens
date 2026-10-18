<?php

namespace App\Services\Reports;

use App\Enums\ReferenceFindingStatus;

/**
 * One bibliography entry as rendered in the report.
 *
 * `status` falls back to `pending` when the reference has no finding row
 * (D-06-11); `confidence`/`reason` stay null in that case.
 */
final readonly class ReportReferenceRow
{
    public function __construct(
        public ?string $rawText,
        public ?string $doi,
        public ?string $title,
        public ?string $authors,
        public ?string $publicationName,
        public ?int $publicationYear,
        public ReferenceFindingStatus $status,
        public ?float $confidence,
        public ?string $reason,
    ) {}
}
