<?php

namespace App\Data\Reference;

use App\Data\BaseData;
use App\Data\Location\LocationPreviewData;
use App\Data\ReferenceFinding\ReferenceFindingPreviewData;
use App\Models\ResearchedDocumentReference;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A bibliography list item (`GET /documents/{document}/references`,
 * `docs/API_SPEC.md` §5) with the finding preview and highlight locations
 * embedded, so the viewer can render highlights without a detail request per
 * reference.
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceSummaryData extends BaseData
{
    /**
     * @param  list<LocationPreviewData>  $locations
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
        public ?ReferenceFindingPreviewData $finding = null,
        #[DataCollectionOf(LocationPreviewData::class)]
        public array $locations = [],
    ) {}

    public static function forReference(ResearchedDocumentReference $reference): self
    {
        $finding = $reference->relationLoaded('finding')
            ? $reference->finding
            : $reference->finding()->first();

        $locations = $reference->relationLoaded('locations')
            ? $reference->locations
            : $reference->locations()->get();

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
            finding: $finding === null ? null : ReferenceFindingPreviewData::fromModel($finding),
            locations: $locations
                ->map(static fn ($location): LocationPreviewData => LocationPreviewData::fromModel($location))
                ->values()
                ->all(),
        );
    }
}
