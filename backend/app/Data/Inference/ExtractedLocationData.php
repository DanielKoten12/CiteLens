<?php

namespace App\Data\Inference;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A bounding box of one extracted reference/citation region on a PDF page.
 *
 * Mirrors the `locations[]` object of the internal extraction contract
 * (`docs/API_SPEC.md` §9). The service also sends `coordinate_system` and a
 * 0-based `location_index`; they are intentionally not modeled here because
 * persistence assigns both canonically (`PersistExtractionStep`). Unmodeled
 * payload fields are ignored, not rejected.
 */
#[MapName(SnakeCaseMapper::class)]
final class ExtractedLocationData extends BaseData
{
    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?float $pageWidth = null,
        public ?float $pageHeight = null,
    ) {}
}
