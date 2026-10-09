<?php

namespace App\Services\Citations;

use App\Services\Scoring\ScoringConfig;

/**
 * Typed access to the citation-matching configuration
 * (`config/scoring.php` → `citation_matching`).
 *
 * Year tolerance deliberately delegates to {@see ScoringConfig} so the reference
 * matcher and the citation matcher cannot drift apart.
 */
final class CitationMatchConfig
{
    public function __construct(
        private readonly ScoringConfig $scoring,
    ) {}

    /**
     * Minimum Jaro-Winkler surname similarity for an APA citation to pair.
     */
    public function surnameThreshold(): float
    {
        return max(0.0, min(1.0, (float) config('scoring.citation_matching.surname_threshold', 0.85)));
    }

    /**
     * Accepted year distance; shared with reference scoring.
     */
    public function yearTolerance(): int
    {
        return $this->scoring->yearTolerance();
    }
}
