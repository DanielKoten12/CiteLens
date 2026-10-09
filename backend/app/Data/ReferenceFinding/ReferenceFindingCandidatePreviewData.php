<?php

namespace App\Data\ReferenceFinding;

use App\Data\BaseData;
use App\Models\ReferenceFindingCandidate;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One ranked Crossref candidate of a reference finding
 * (`docs/API_SPEC.md` §5 reference detail).
 */
#[MapName(SnakeCaseMapper::class)]
final class ReferenceFindingCandidatePreviewData extends BaseData
{
    public function __construct(
        public string $id,
        public int $rank,
        public float $confidence,
        public ?string $doi,
        public ?string $title,
        public ?string $authors,
        public ?string $publicationName,
        public ?int $publicationYear,
        public ?string $url,
        public ?string $matchReason,
    ) {}

    public static function fromModel(ReferenceFindingCandidate $candidate): self
    {
        return new self(
            id: $candidate->getKey(),
            rank: $candidate->rank,
            confidence: $candidate->confidence,
            doi: $candidate->doi,
            title: $candidate->title,
            authors: $candidate->authors,
            publicationName: $candidate->publication_name,
            publicationYear: $candidate->publication_year,
            url: $candidate->url,
            matchReason: $candidate->match_reason,
        );
    }
}
