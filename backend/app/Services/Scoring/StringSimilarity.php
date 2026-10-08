<?php

namespace App\Services\Scoring;

/**
 * Unicode-safe string similarity primitives.
 *
 * PHP's native `levenshtein()` is byte-based and wrong for diacritics, so both
 * metrics operate on `mb_str_split()` code points. Empty inputs return `null`
 * (signal unavailable) rather than `0.0`, so the scorer can redistribute a
 * missing signal's weight instead of penalizing it.
 */
final class StringSimilarity
{
    /**
     * Lowercase, punctuation-stripped comparison form.
     */
    public function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Normalized Levenshtein similarity (`1 - distance / max length`).
     */
    public function levenshtein(?string $a, ?string $b): ?float
    {
        $left = $this->normalize($a);
        $right = $this->normalize($b);

        if ($left === '' || $right === '') {
            return null;
        }

        if ($left === $right) {
            return 1.0;
        }

        $leftChars = mb_str_split($left);
        $rightChars = mb_str_split($right);

        $distance = $this->editDistance($leftChars, $rightChars);
        $longest = max(count($leftChars), count($rightChars));

        return max(0.0, min(1.0, 1.0 - ($distance / $longest)));
    }

    /**
     * Jaro-Winkler similarity, used for author names.
     */
    public function jaroWinkler(?string $a, ?string $b): ?float
    {
        $left = $this->normalize($a);
        $right = $this->normalize($b);

        if ($left === '' || $right === '') {
            return null;
        }

        return $this->jaroWinklerNormalized($left, $right);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function editDistance(array $a, array $b): int
    {
        $previous = range(0, count($b));

        foreach ($a as $i => $leftChar) {
            $current = [$i + 1];

            foreach ($b as $j => $rightChar) {
                $cost = $leftChar === $rightChar ? 0 : 1;

                $current[$j + 1] = min(
                    $current[$j] + 1,
                    $previous[$j + 1] + 1,
                    $previous[$j] + $cost,
                );
            }

            $previous = $current;
        }

        return $previous[count($b)];
    }

    private function jaroWinklerNormalized(string $a, string $b): float
    {
        $left = mb_str_split($a);
        $right = mb_str_split($b);

        $leftCount = count($left);
        $rightCount = count($right);

        if ($leftCount === 0 || $rightCount === 0) {
            return 0.0;
        }

        $window = max(0, (int) floor(max($leftCount, $rightCount) / 2) - 1);

        $leftMatched = array_fill(0, $leftCount, false);
        $rightMatched = array_fill(0, $rightCount, false);
        $matches = 0;

        foreach ($left as $i => $char) {
            $start = max(0, $i - $window);
            $end = min($i + $window, $rightCount - 1);

            for ($j = $start; $j <= $end; $j++) {
                if ($rightMatched[$j] || $right[$j] !== $char) {
                    continue;
                }

                $leftMatched[$i] = true;
                $rightMatched[$j] = true;
                $matches++;

                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $j = 0;

        foreach ($left as $i => $char) {
            if (! $leftMatched[$i]) {
                continue;
            }

            while (! $rightMatched[$j]) {
                $j++;
            }

            if ($char !== $right[$j]) {
                $transpositions++;
            }

            $j++;
        }

        $transpositions /= 2;

        $jaro = (
            ($matches / $leftCount)
            + ($matches / $rightCount)
            + (($matches - $transpositions) / $matches)
        ) / 3;

        $prefix = 0;

        for ($i = 0; $i < min(4, $leftCount, $rightCount); $i++) {
            if ($left[$i] !== $right[$i]) {
                break;
            }

            $prefix++;
        }

        return max(0.0, min(1.0, $jaro + ($prefix * 0.1 * (1.0 - $jaro))));
    }
}
