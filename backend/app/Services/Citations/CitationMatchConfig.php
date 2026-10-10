<?php

namespace App\Services\Citations;

use InvalidArgumentException;

/**
 * Typed access to the citation-matching configuration
 * (`config/scoring.php` → `citation_matching`).
 *
 * Unlike Phase 05 there is no year tolerance: year is a weighted signal rather
 * than a hard gate, so a wrong-year candidate is penalised instead of rejected
 * (D-05.1-06). Defaults are provisional and calibrated by the evaluation
 * harness / Phase 07 (D-05.1-12).
 */
final class CitationMatchConfig
{
    /**
     * @return array{surnames: float, year: float}
     *
     * @throws InvalidArgumentException when the configured weights do not sum to 1.0
     */
    public function weights(): array
    {
        $weights = [
            'surnames' => $this->clamp((float) config('scoring.citation_matching.weights.surnames', 0.70)),
            'year' => $this->clamp((float) config('scoring.citation_matching.weights.year', 0.30)),
        ];

        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException(sprintf('Citation matching weights must sum to 1.0, got %.4f.', $sum));
        }

        return $weights;
    }

    /**
     * Combined score at which an APA pairing is committed.
     */
    public function commitThreshold(): float
    {
        return $this->clamp((float) config('scoring.citation_matching.commit_threshold', 0.90));
    }

    /**
     * Combined score at which a reference is offered as a candidate; below this
     * the citation has no plausible match (a true hallucination).
     */
    public function proposalThreshold(): float
    {
        return $this->clamp((float) config('scoring.citation_matching.proposal_threshold', 0.50));
    }

    /**
     * How much a committed pair must beat the runner-up by (Phase 05.1 W2).
     */
    public function winnerMargin(): float
    {
        return $this->clamp((float) config('scoring.citation_matching.winner_margin', 0.08));
    }

    /**
     * Year distance at which the year signal reaches 0 (1.0 at distance 0).
     */
    public function yearWindow(): int
    {
        return max(1, (int) config('scoring.citation_matching.year_window', 5));
    }

    /**
     * Penalty applied to the surname signal when both sides expose a first
     * initial and they differ.
     */
    public function initialPenalty(): float
    {
        return $this->clamp((float) config('scoring.citation_matching.initial_penalty', 0.15));
    }

    /**
     * Maximum alternatives persisted/exposed per citation (W2).
     */
    public function maxCandidates(): int
    {
        return max(1, (int) config('scoring.citation_matching.max_candidates', 3));
    }

    /**
     * Whether a validated GROBID `reference_index` may drive a pairing.
     */
    public function trustExtractionHint(): bool
    {
        return (bool) config('scoring.citation_matching.trust_extraction_hint', true);
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
