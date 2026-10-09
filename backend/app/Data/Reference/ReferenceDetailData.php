<?php

namespace App\Data\Reference;

use App\Data\BaseData;
use App\Data\Citation\CitationOccurrenceData;
use App\Data\Location\LocationPreviewData;
use App\Data\ReferenceFinding\ReferenceFindingDetailData;
use App\Models\ResearchedDocumentReference;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full reference detail (`GET /references/{reference}`, `docs/API_SPEC.md` §5):
 * the bibliography fields plus highlight locations, the finding with its ranked
 * candidates, and the resolved citations.
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceDetailData extends BaseData
{
    /**
     * @param  list<LocationPreviewData>  $locations
     * @param  list<CitationOccurrenceData>  $citations
     */
    public function __construct(
        public string $id,
        public ?string $rawText,
        public ?string $doi,
        public ?string $title,
        public ?string $authors,
        public ?string $publicationName,
        public ?int $publicationYear,
        public ?int $textStartOffset,
        public ?int $textEndOffset,
        public ?ReferenceFindingDetailData $finding = null,
        #[DataCollectionOf(LocationPreviewData::class)]
        public array $locations = [],
        #[DataCollectionOf(CitationOccurrenceData::class)]
        public array $citations = [],
    ) {}

    public static function forReference(ResearchedDocumentReference $reference): self
    {
        $finding = $reference->relationLoaded('finding')
            ? $reference->finding
            : $reference->finding()->first();

        $locations = $reference->relationLoaded('locations')
            ? $reference->locations
            : $reference->locations()->get();

        $citations = $reference->relationLoaded('citations')
            ? $reference->citations
            : $reference->citations()->get();

        return new self(
            id: $reference->getKey(),
            rawText: $reference->raw_text,
            doi: $reference->doi,
            title: $reference->title,
            authors: $reference->authors,
            publicationName: $reference->publication_name,
            publicationYear: $reference->publication_year,
            textStartOffset: $reference->text_start_offset,
            textEndOffset: $reference->text_end_offset,
            finding: $finding === null ? null : ReferenceFindingDetailData::fromModel($finding),
            locations: $locations
                ->map(static fn ($location): LocationPreviewData => LocationPreviewData::fromModel($location))
                ->values()
                ->all(),
            citations: $citations
                ->map(static fn ($citation): CitationOccurrenceData => CitationOccurrenceData::fromModel($citation))
                ->values()
                ->all(),
        );
    }
}
