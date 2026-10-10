<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;

/**
 * Outcome of resolving one citation marker against a document's references.
 *
 * `state` drives the derived citation status; `referenceId` is the selected
 * pairing (only when `state = paired`). `confidence` and `method` are the
 * provenance persisted in Phase 05.1 W2; `candidates` are the ranked
 * alternatives offered to the reviewer.
 */
final class CitationResolution
{
    /**
     * @param  list<CitationCandidate>  $candidates  ranked 1..N
     */
    public function __construct(
        public readonly CitationResolutionState $state,
        public readonly ?string $referenceId = null,
        public readonly ?float $confidence = null,
        public readonly ?CitationResolutionMethod $method = null,
        public readonly ?int $hintIndex = null,
        public readonly array $candidates = [],
    ) {}

    public function isPaired(): bool
    {
        return $this->state === CitationResolutionState::Paired && $this->referenceId !== null;
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     */
    public static function paired(
        string $referenceId,
        ?float $confidence,
        CitationResolutionMethod $method,
        ?int $hintIndex = null,
        array $candidates = [],
    ): self {
        return new self(
            state: CitationResolutionState::Paired,
            referenceId: $referenceId,
            confidence: $confidence,
            method: $method,
            hintIndex: $hintIndex,
            candidates: $candidates,
        );
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     */
    public static function unmatched(?int $hintIndex = null, array $candidates = []): self
    {
        return new self(
            state: CitationResolutionState::Unmatched,
            hintIndex: $hintIndex,
            candidates: $candidates,
        );
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     */
    public static function unresolved(
        ?float $confidence,
        ?CitationResolutionMethod $method = null,
        ?int $hintIndex = null,
        array $candidates = [],
    ): self {
        return new self(
            state: CitationResolutionState::Unresolved,
            confidence: $confidence,
            method: $method,
            hintIndex: $hintIndex,
            candidates: $candidates,
        );
    }
}
