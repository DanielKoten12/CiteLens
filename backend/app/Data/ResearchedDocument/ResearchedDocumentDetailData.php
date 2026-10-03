<?php

namespace App\Data\ResearchedDocument;

use App\Data\ModelData;
use App\Data\File\FilePreviewData;
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

    public string $status;
    #[MapInputName('analysis_progress')]
    public int $progress;
    #[MapInputName('analysis_step')]
    public ?string $currentStep = null;
    #[MapInputName('analysis_error')]
    public ?string $error = null;

    public ?CarbonImmutable $createdAt = null;
    public ?CarbonImmutable $updatedAt = null;

    public ?FilePreviewData $file = null;
}
