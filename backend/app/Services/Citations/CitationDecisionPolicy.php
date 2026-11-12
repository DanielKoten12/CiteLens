<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionState;

/**
 * Turns a ranked candidate list into a resolution state (Phase 05.1 W2).
 *
 * - no candidate at all → `unmatched` (a true hallucination);
 * - best candidate below `commit_threshold` → `unresolved` (plausible but not
 *   confident enough to commit);
 * - best candidate at/above `commit_threshold` but within `winner_margin` of the
 *   runner-up → `unresolved` (ambiguous, forcing a pair would be a guess);
 * - otherwise → `paired`.
 *
 * This is the deliberate behavioral change that makes a resolver miss a
 * medium-severity `unresolved` instead of a high-severity `hallucination`
 * (D-05.1-01/D-05.1-06).
 */
final class CitationDecisionPolicy
{
    public function __construct(
        private readonly CitationMatchConfig $config,
    ) {}

    /**
     * @param  list<CitationCandidate>  $ranked  candidates at/above the proposal threshold
     */
    public function stateFor(array $ranked): CitationResolutionState
    {
        $best = $ranked[0] ?? null;

        if ($best === null) {
            return CitationResolutionState::Unmatched;
        }

        if ($best->confidence < $this->config->commitThreshold()) {
            return CitationResolutionState::Unresolved;
        }

        $runnerUp = $ranked[1] ?? null;

        if ($runnerUp !== null && ($best->confidence - $runnerUp->confidence) < $this->config->winnerMargin()) {
            return CitationResolutionState::Unresolved;
        }

        return CitationResolutionState::Paired;
    }
}
