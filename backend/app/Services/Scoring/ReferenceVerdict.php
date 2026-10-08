<?php

namespace App\Services\Scoring;

use App\Enums\ReferenceFindingStatus;

/**
 * The final verdict for one reference, produced by {@see VerdictDecider} and
 * persisted by `App\Services\ReferenceFinding\ReferenceFindingWriter`.
 *
 * `selectedRank` points at the candidate to store in
 * `reference_findings.selected_candidate_id` (1..N), or `null` when no candidate
 * is selected. `candidates` is already ranked best-first.
 */
final class ReferenceVerdict
{
    /**
     * @param  list<ScoredCandidate>  $candidates
     */
    public function __construct(
        public readonly string $referenceId,
        public readonly ReferenceFindingStatus $status,
        public readonly ?float $confidence,
        public readonly ?string $reason,
        public readonly ?int $selectedRank,
        public readonly array $candidates,
    ) {}
}
