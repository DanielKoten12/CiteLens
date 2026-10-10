<?php

namespace App\Data\Report;

use App\Data\BaseData;
use App\Enums\ReportStatus;
use App\Models\GeneratedDocumentReport;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A report list item / action response
 * (`POST /documents/{document}/reports`, `GET /documents/{document}/reports`).
 *
 * `download_url` is null until the report is `completed`; it is resolved by the
 * controller through `PrivateFileUrlResolver` (D-06-03/D-06-04).
 */
#[MapName(SnakeCaseMapper::class)]
final class GeneratedDocumentReportSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $documentId,
        public ReportStatus $status,
        public ?string $downloadUrl = null,
        public ?CarbonImmutable $generatedAt = null,
    ) {}

    /**
     * Named `forReport` (not `fromReport`) so spatie/laravel-data does not treat
     * it as a custom creation method and recurse through `self::from()`.
     */
    public static function forReport(GeneratedDocumentReport $report, ?string $downloadUrl): self
    {
        return new self(
            id: $report->getKey(),
            documentId: $report->researched_document_id,
            status: $report->status,
            downloadUrl: $downloadUrl,
            generatedAt: $report->generated_at?->toImmutable(),
        );
    }
}
