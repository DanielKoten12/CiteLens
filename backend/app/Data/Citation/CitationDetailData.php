<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Data\Location\LocationPreviewData;
use App\Enums\CitationStatus;
use App\Models\ResearchedDocumentCitation;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full citation detail (`GET /citations/{citation}`, `docs/API_SPEC.md` §6):
 * the list fields plus the highlight locations.
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationDetailData extends BaseData
{
    /**
     * @param  list<LocationPreviewData>  $locations
     */
    public function __construct(
        public string $id,
        public string $citationText,
        public ?string $citationMarker,
        public ?string $contextBefore,
        public ?string $contextAfter,
        public ?int $textStartOffset,
        public ?int $textEndOffset,
        public ?int $occurrenceIndex,
        public CitationStatus $status,
        public ?CitationReferencePreviewData $reference = null,
        #[DataCollectionOf(LocationPreviewData::class)]
        public array $locations = [],
    ) {}

    public static function forCitation(ResearchedDocumentCitation $citation, CitationStatus $status): self
    {
        return new self(
            id: $citation->getKey(),
            citationText: $citation->citation_text,
            citationMarker: $citation->citation_marker,
            contextBefore: $citation->context_before,
            contextAfter: $citation->context_after,
            textStartOffset: $citation->text_start_offset,
            textEndOffset: $citation->text_end_offset,
            occurrenceIndex: $citation->occurrence_index,
            status: $status,
            reference: $citation->reference === null
                ? null
                : CitationReferencePreviewData::fromModel($citation->reference),
            locations: $citation->locations
                ->map(static fn ($location): LocationPreviewData => LocationPreviewData::fromModel($location))
                ->values()
                ->all(),
        );
    }
}
