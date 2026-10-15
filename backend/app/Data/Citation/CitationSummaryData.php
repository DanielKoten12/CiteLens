<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Enums\CitationResolutionMethod;
use App\Enums\CitationStatus;
use App\Models\ResearchedDocumentCitation;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A citation list item (`GET /documents/{document}/citations`, `docs/API_SPEC.md` §6).
 *
 * `status` is **derived** from the pairing plus the paired reference finding and
 * is passed in by the caller (computed exactly once through the shared
 * resolver), never mapped from a model attribute.
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationSummaryData extends BaseData
{
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
        public ?CitationResolutionMethod $resolutionMethod = null,
        public ?CitationReferencePreviewData $reference = null,
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
            resolutionMethod: $citation->resolution_method,
            reference: $citation->reference === null
                ? null
                : CitationReferencePreviewData::fromModel($citation->reference),
        );
    }
}
