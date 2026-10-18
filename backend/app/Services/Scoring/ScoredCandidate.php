<?php

namespace App\Services\Scoring;

use App\Services\Crossref\CrossrefWorkData;

/**
 * One scored candidate, in final rank order (`rank` 1..N, best first).
 */
final class ScoredCandidate
{
    public function __construct(
        public readonly CrossrefWorkData $work,
        public readonly ScoreBreakdown $breakdown,
        public readonly int $rank,
        public readonly string $matchReason,
    ) {}

    public function confidence(): float
    {
        return $this->breakdown->final;
    }

    public function doi(): ?string
    {
        return $this->work->doi;
    }
}
