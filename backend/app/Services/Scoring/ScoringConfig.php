<?php

namespace App\Services\Scoring;

use InvalidArgumentException;

/**
 * Typed access to `config/scoring.php` (thresholds, weights, semantic blend,
 * year tolerance, local-venue keywords).
 *
 * The weights must sum to 1.0 (±0.001); a misconfiguration fails loudly at the
 * first run instead of producing skewed scores. All values are clamped to the
 * canonical 0..1 signal range.
 */
final class ScoringConfig
{
    public function validThreshold(): float
    {
        return $this->threshold('valid', 0.85);
    }

    public function suspiciousThreshold(): float
    {
        return $this->threshold('suspicious', 0.50);
    }

    /**
     * @return array{title: float, authors: float, journal: float, year: float}
     *
     * @throws InvalidArgumentException when the configured weights do not sum to 1.0
     */
    public function weights(): array
    {
        $weights = [
            'title' => (float) config('scoring.weights.title', 0.45),
            'authors' => (float) config('scoring.weights.authors', 0.25),
            'journal' => (float) config('scoring.weights.journal', 0.15),
            'year' => (float) config('scoring.weights.year', 0.15),
        ];

        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException(sprintf('Scoring weights must sum to 1.0, got %.4f.', $sum));
        }

        return $weights;
    }

    public function yearTolerance(): int
    {
        return max(0, (int) config('scoring.year_tolerance', 1));
    }

    public function semanticEnabled(): bool
    {
        return (bool) config('scoring.semantic.enabled', true);
    }

    /**
     * Weight of the semantic term inside the title signal (rest is string similarity).
     */
    public function semanticTitleBlend(): float
    {
        return $this->clamp((float) config('scoring.semantic.title_blend', 0.6));
    }

    /**
     * @return list<string>
     */
    public function localVenueKeywords(): array
    {
        $keywords = config('scoring.local_venue_keywords', []);

        if (! is_array($keywords)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $keyword): string => is_string($keyword) ? trim($keyword) : '',
            $keywords,
        ), static fn (string $keyword): bool => $keyword !== ''));
    }

    private function threshold(string $key, float $default): float
    {
        return $this->clamp((float) config("scoring.thresholds.{$key}", $default));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
