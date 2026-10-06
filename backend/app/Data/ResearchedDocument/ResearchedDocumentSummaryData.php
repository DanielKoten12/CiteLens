<?php

namespace App\Data\ResearchedDocument;

use App\Data\BaseData;
use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A document list item (`GET /documents`, `docs/API_SPEC.md` §4).
 *
 * `summary` is `null` until the analysis has completed (OQ-10).
 */
#[MapName(SnakeCaseMapper::class)]
final class ResearchedDocumentSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public DocumentStatus $status,
        #[MapInputName('analysis_progress')]
        public int $progress,
        public ?DocumentAnalysisSummaryData $summary = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    /**
     * Build a list item, attaching the derived summary when one was computed.
     *
     * Named `forDocument` (not `fromDocument`) so spatie/laravel-data does not
     * treat it as a custom creation method and recurse through `self::from()`.
     */
    public static function forDocument(
        ResearchedDocument $document,
        ?DocumentAnalysisSummaryData $summary,
    ): self {
        $data = self::from($document);
        $data->summary = $summary;

        return $data;
    }
}
