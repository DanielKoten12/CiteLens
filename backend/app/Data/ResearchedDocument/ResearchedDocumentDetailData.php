<?php

namespace App\Data\ResearchedDocument;

use App\Data\File\FilePreviewData;
use App\Data\ModelData;
use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full representation of a researched document: the upload response
 * (`POST /documents`) and the document detail (`GET /documents/{document}`).
 *
 * Mirrors the document object in `docs/API_SPEC.md` §4.
 */
#[MapName(SnakeCaseMapper::class)]
class ResearchedDocumentDetailData extends ModelData
{
    public string $id;

    public string $name;

    public DocumentStatus $status;

    #[MapInputName('analysis_progress')]
    public int $progress;

    #[MapInputName('analysis_step')]
    public ?AnalysisStep $currentStep = null;

    #[MapInputName('analysis_error')]
    public ?string $error = null;

    public ?CarbonImmutable $createdAt = null;

    public ?CarbonImmutable $updatedAt = null;

    public ?FilePreviewData $file = null;

    /**
     * Derived analysis counts; `null` until the analysis has completed (OQ-10).
     */
    public ?DocumentAnalysisSummaryData $summary = null;

    /**
     * Build the detail representation, attaching the derived summary when one was
     * computed (only completed documents have one).
     *
     * Named `forDocument`, not `fromDocument`: spatie/laravel-data treats every
     * `from*` method that accepts the model as a custom creation method and would
     * recurse through `self::from()`.
     */
    public static function forDocument(
        ResearchedDocument $document,
        ?DocumentAnalysisSummaryData $summary = null,
    ): self {
        $data = self::from($document);
        $data->summary = $summary;

        return $data;
    }
}
