<?php

namespace App\Services\Citations;

use App\Services\Scoring\AuthorMatcher;
use App\Services\Scoring\StringSimilarity;

/**
 * Pairs one parsed citation marker with at most one reference of the same
 * document (Phase 05, `docs/plans/backend/05-citation-resolution-and-review-detail.md` §6.5).
 *
 * - IEEE: the first/lowest ordinal maps to bibliography position 1..N (OQ-18).
 * - APA: the cited surnames and year are compared against every reference's
 *   authors (`Jaro-Winkler`) and publication year (tolerance from config). The
 *   year is a hard gate only when both sides carry it; a missing surname signal
 *   is never treated as a penalty. A score below the configured threshold stays
 *   unpaired, because a false pairing is worse than a `hallucination` verdict.
 *
 * The resolver is pure and only receives references of one document, so it can
 * never pair across documents.
 */
final class CitationResolver
{
    public function __construct(
        private readonly CitationMatchConfig $config,
        private readonly StringSimilarity $strings,
        private readonly AuthorMatcher $authors,
    ) {}

    /**
     * @param  list<CitationReference>  $references  bibliography order (index 1..N)
     */
    public function resolve(ParsedCitationMarker $marker, array $references): CitationResolution
    {
        if ($marker->isIeee()) {
            return $this->resolveIeee($marker, $references);
        }

        if ($marker->isApa()) {
            return $this->resolveApa($marker, $references);
        }

        return CitationResolution::unpaired('unparsed');
    }

    /**
     * @param  list<CitationReference>  $references
     */
    private function resolveIeee(ParsedCitationMarker $marker, array $references): CitationResolution
    {
        $ordinal = $marker->ordinals[0] ?? null;

        foreach ($references as $reference) {
            if ($reference->bibliographyIndex === $ordinal) {
                return new CitationResolution($reference->id, 1.0, 'ieee_ordinal');
            }
        }

        return CitationResolution::unpaired('ieee_ordinal_out_of_range');
    }

    /**
     * @param  list<CitationReference>  $references
     */
    private function resolveApa(ParsedCitationMarker $marker, array $references): CitationResolution
    {
        $best = null;
        $bestScore = null;

        foreach ($references as $reference) {
            if (! $this->yearMatches($marker->year, $reference->publicationYear)) {
                continue;
            }

            $score = $this->surnameScore($marker->surnames, $reference->authors);

            if ($score === null) {
                continue;
            }

            $isBetter = $bestScore === null
                || $score > $bestScore
                || ($score === $bestScore && $best !== null && $reference->bibliographyIndex < $best->bibliographyIndex);

            if ($isBetter) {
                $best = $reference;
                $bestScore = $score;
            }
        }

        if ($best === null || $bestScore === null || $bestScore < $this->config->surnameThreshold()) {
            return CitationResolution::unpaired('apa_below_threshold');
        }

        return new CitationResolution($best->id, round($bestScore, 4), 'apa_surname_year');
    }

    /**
     * A missing year on either side is not a mismatch; a known year outside the
     * tolerance is.
     */
    private function yearMatches(?int $citedYear, ?int $referenceYear): bool
    {
        if ($citedYear === null || $referenceYear === null) {
            return true;
        }

        return abs($citedYear - $referenceYear) <= $this->config->yearTolerance();
    }

    /**
     * Best-pair average surname similarity.
     *
     * The denominator is the number of **cited** surnames, so a cited author
     * that the reference does not carry lowers the score (a truncated `et al.`
     * citation with fewer surnames than the reference is not penalized).
     *
     * @param  list<string>  $citedSurnames
     */
    private function surnameScore(array $citedSurnames, ?string $referenceAuthors): ?float
    {
        if ($citedSurnames === []) {
            return null;
        }

        $referenceSurnames = $this->authors->surnames($referenceAuthors);

        if ($referenceSurnames === []) {
            return null;
        }

        $used = [];
        $scores = [];

        foreach ($citedSurnames as $citedSurname) {
            $best = null;
            $bestIndex = null;

            foreach ($referenceSurnames as $index => $referenceSurname) {
                if (isset($used[$index])) {
                    continue;
                }

                $similarity = $this->strings->jaroWinkler($citedSurname, $referenceSurname) ?? 0.0;

                if ($best === null || $similarity > $best) {
                    $best = $similarity;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                continue;
            }

            $used[$bestIndex] = true;
            $scores[] = $best;
        }

        if ($scores === []) {
            return null;
        }

        return max(0.0, min(1.0, array_sum($scores) / count($citedSurnames)));
    }
}
