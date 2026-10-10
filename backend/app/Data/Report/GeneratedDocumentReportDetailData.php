<?php

namespace App\Data\Report;

use App\Data\BaseData;
use App\Enums\ReportStatus;
use App\Models\GeneratedDocumentReport;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full report detail (`GET /reports/{report}`, `docs/API_SPEC.md` §8).
 *
 * Adds the safe `error` message that is only populated for `failed` reports;
 * `download_url` is null until the report is `completed`.
 */
#[MapName(SnakeCaseMapper::class)]
final class GeneratedDocumentReportDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $documentId,
        public ReportStatus $status,
        public ?string $downloadUrl = null,
        public ?string $error = null,
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
            error: $report->error,
            generatedAt: $report->generated_at?->toImmutable(),
        );
    }
}
