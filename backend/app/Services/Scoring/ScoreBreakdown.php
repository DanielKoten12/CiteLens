<?php

namespace App\Services\Scoring;

/**
 * Per-candidate signal breakdown produced by {@see ReferenceScorer}.
 *
 * `signals` values are `0.0..1.0` or `null` (input missing on either side);
 * `final` is the weighted average over available signals.
 */
final class ScoreBreakdown
{
    /**
     * @param  array{title: ?float, authors: ?float, journal: ?float, year: ?float}  $signals
     * @param  list<string>  $conflicts  field names whose signal fell below the conflict floor
     */
    public function __construct(
        public readonly array $signals,
        public readonly float $final,
        public readonly bool $doiMatch,
        public readonly bool $semanticUsed,
        public readonly bool $semanticDegraded,
        public readonly array $conflicts = [],
    ) {}

    public function signal(string $key): ?float
    {
        return $this->signals[$key] ?? null;
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
