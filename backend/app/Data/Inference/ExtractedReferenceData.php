<?php

namespace App\Data\Inference;

use App\Data\BaseData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One bibliography entry returned by the internal extraction service.
 *
 * Mirrors `references[]` of `docs/API_SPEC.md` §9. Every field except the
 * location list is optional: GROBID regularly cannot parse a field.
 */
#[MapName(SnakeCaseMapper::class)]
final class ExtractedReferenceData extends BaseData
{
    /**
     * @param  list<ExtractedLocationData>  $locations
     */
    public function __construct(
        public ?string $rawText = null,
        public ?string $doi = null,
        public ?string $title = null,
        public ?string $authors = null,
        public ?string $publicationName = null,
        public ?int $publicationYear = null,
        public ?int $textStartOffset = null,
        public ?int $textEndOffset = null,
        #[DataCollectionOf(ExtractedLocationData::class)]
        public array $locations = [],
    ) {}
}
