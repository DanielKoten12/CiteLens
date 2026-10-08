<?php

namespace App\Services\Scoring;

/**
 * Conservative heuristic for likely non-indexed/local venues.
 *
 * Matches the configured keywords against the reference's publication name and
 * the best candidate's container title. With the shipped empty keyword list it
 * always returns `false`; it never invents a match.
 */
final class LocalVenueDetector
{
    public function __construct(
        private readonly ScoringConfig $config,
    ) {}

    public function looksLocal(ScoringReference $reference, ?ScoredCandidate $best): bool
    {
        $keywords = $this->config->localVenueKeywords();

        if ($keywords === []) {
            return false;
        }

        $haystacks = array_values(array_filter([
            $reference->publicationName,
            $best?->work->containerTitle,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== ''));

        foreach ($keywords as $keyword) {
            foreach ($haystacks as $haystack) {
                if (mb_stripos($haystack, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
