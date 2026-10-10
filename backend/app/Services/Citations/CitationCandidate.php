<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionMethod;

/**
 * One scored reference suggestion for a citation.
 *
 * Candidates are the unit the resolver reasons about and, from Phase 05.1 W2,
 * the rows persisted in `citation_resolution_candidates`.
 */
final class CitationCandidate
{
    public function __construct(
        public readonly string $referenceId,
        public readonly float $confidence,
        public readonly CitationResolutionMethod $method,
        public readonly ?string $matchReason,
        public readonly int $rank = 0,
    ) {}

    public function withRank(int $rank): self
    {
        return new self(
            referenceId: $this->referenceId,
            confidence: $this->confidence,
            method: $this->method,
            matchReason: $this->matchReason,
            rank: $rank,
        );
    }
}
