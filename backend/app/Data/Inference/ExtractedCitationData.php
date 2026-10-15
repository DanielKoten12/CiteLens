<?php

namespace App\Data\Inference;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One in-text citation occurrence returned by the internal extraction service.
 *
 * Mirrors `citations[]` of `docs/API_SPEC.md` §9. `reference_index` is the
 * inferer's own bibliography hint; it is **not persisted** (no column) and is
 * only meaningful within one extraction run.
 */
#[MapName(SnakeCaseMapper::class)]
final class ExtractedCitationData extends BaseData
{
    /**
     * @param  list<ExtractedLocationData>  $locations
     */
    public function __construct(
        public string $citationText = '',
        public ?string $citationMarker = null,
        public ?string $contextBefore = null,
        public ?string $contextAfter = null,
        public ?int $textStartOffset = null,
        public ?int $textEndOffset = null,
        public ?int $occurrenceIndex = null,
        public ?int $referenceIndex = null,
        #[DataCollectionOf(ExtractedLocationData::class)]
        public array $locations = [],
    ) {}
}
