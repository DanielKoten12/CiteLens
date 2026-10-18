<?php

namespace App\Data\ResearchedDocument;

use App\Data\BaseData;
use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Lightweight polling payload (`GET /documents/{document}/status`,
 * `docs/API_SPEC.md` §4) and the retry response.
 *
 * Intentionally small: no file, no summary, no counts.
 */
#[MapName(SnakeCaseMapper::class)]
final class ResearchedDocumentStatusData extends BaseData
{
    public function __construct(
        public string $id,
        public DocumentStatus $status,
        #[MapInputName('analysis_progress')]
        public int $progress,
        #[MapInputName('analysis_step')]
        public ?AnalysisStep $currentStep = null,
        #[MapInputName('analysis_error')]
        public ?string $error = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}
}
