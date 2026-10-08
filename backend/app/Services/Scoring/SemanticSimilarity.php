<?php

namespace App\Services\Scoring;

/**
 * Cosine similarity over embedding vectors.
 *
 * Returns `null` (signal unavailable) for missing, empty, zero-length,
 * mismatched-dimension or non-numeric vectors so the scorer never fabricates a
 * semantic signal.
 */
final class SemanticSimilarity
{
    /**
     * @param  list<float>|null  $a
     * @param  list<float>|null  $b
     */
    public function cosine(?array $a, ?array $b): ?float
    {
        if ($a === null || $b === null || $a === [] || $b === [] || count($a) !== count($b)) {
            return null;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = $b[$index] ?? null;

            if (! is_numeric($value) || ! is_numeric($other)) {
                return null;
            }

            $value = (float) $value;
            $other = (float) $other;

            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return null;
        }

        return max(-1.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }
}
