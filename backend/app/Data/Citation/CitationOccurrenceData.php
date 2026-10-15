<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Models\ResearchedDocumentCitation;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A resolved citation occurrence embedded in the reference detail response
 * (`docs/API_SPEC.md` §5).
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationOccurrenceData extends BaseData
{
    public function __construct(
        public string $id,
        public string $citationText,
        public ?int $occurrenceIndex,
    ) {}

    public static function fromModel(ResearchedDocumentCitation $citation): self
    {
        return new self(
            id: $citation->getKey(),
            citationText: $citation->citation_text,
            occurrenceIndex: $citation->occurrence_index,
        );
    }
}
