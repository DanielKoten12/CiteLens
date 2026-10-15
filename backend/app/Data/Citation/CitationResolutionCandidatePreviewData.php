<?php

namespace App\Data\Citation;

use App\Data\BaseData;
use App\Enums\CitationResolutionMethod;
use App\Models\CitationResolutionCandidate;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One ranked alternative reference for a citation (`docs/API_SPEC.md` §6,
 * Phase 05.1 W2). The `reference` preview lets the reviewer pick a suggestion.
 */
#[MapName(SnakeCaseMapper::class)]
final class CitationResolutionCandidatePreviewData extends BaseData
{
    public function __construct(
        public string $id,
        public int $rank,
        public float $confidence,
        public CitationResolutionMethod $method,
        public ?string $matchReason,
        public ?CitationReferencePreviewData $reference = null,
    ) {}

    public static function fromModel(CitationResolutionCandidate $candidate): self
    {
        $reference = $candidate->relationLoaded('reference')
            ? $candidate->reference
            : $candidate->reference()->first();

        return new self(
            id: $candidate->getKey(),
            rank: $candidate->rank,
            confidence: $candidate->confidence,
            method: $candidate->method,
            matchReason: $candidate->match_reason,
            reference: $reference === null ? null : CitationReferencePreviewData::fromModel($reference),
        );
    }
}
