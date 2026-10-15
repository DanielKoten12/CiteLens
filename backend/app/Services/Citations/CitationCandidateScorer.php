<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionMethod;
use App\Services\Scoring\AuthorName;
use App\Services\Scoring\StringSimilarity;

/**
 * Scores one parsed APA author/year pair against a document's references.
 *
 * Signals (D-05.1-06):
 * - `surnames`: best-pair average Jaro-Winkler, with an initials penalty when
 *   both sides expose a first initial and they differ (D-05.1-11);
 * - `year`: a graded score over `year_window` instead of a hard gate, so a
 *   preprint/published-year gap degrades gracefully.
 *
 * Missing signals are renormalized away (never treated as `0`), matching the
 * Phase 04 scoring philosophy. The class is pure: value objects in, value
 * objects out.
 */
final class CitationCandidateScorer
{
    public function __construct(
        private readonly CitationMatchConfig $config,
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * @param  list<CitationReference>  $references
     * @return list<CitationCandidate> ranked best-first; only candidates at or
     *                                 above `proposal_threshold`
     */
    public function score(ParsedAuthorYear $pair, array $references): array
    {
        $scored = [];

        foreach ($references as $reference) {
            $signals = $this->signals($pair, $reference);

            if ($signals === null) {
                continue;
            }

            if ($signals['confidence'] < $this->config->proposalThreshold()) {
                continue;
            }

            $scored[] = [
                'reference' => $reference,
                'confidence' => $signals['confidence'],
                'reason' => $signals['reason'],
            ];
        }

        usort($scored, static function (array $left, array $right): int {
            $byConfidence = $right['confidence'] <=> $left['confidence'];

            if ($byConfidence !== 0) {
                return $byConfidence;
            }

            return $left['reference']->bibliographyIndex <=> $right['reference']->bibliographyIndex;
        });

        $candidates = [];

        foreach ($scored as $index => $item) {
            $candidates[] = new CitationCandidate(
                referenceId: $item['reference']->id,
                confidence: round($item['confidence'], 4),
                method: CitationResolutionMethod::Apa,
                matchReason: $item['reason'],
                rank: $index + 1,
            );
        }

        return $candidates;
    }

    /**
     * Score one reference, or null when no weighted signal is available.
     *
     * @return array{confidence: float, reason: string}|null
     */
    private function signals(ParsedAuthorYear $pair, CitationReference $reference): ?array
    {
        $weights = $this->config->weights();
        $surnames = $this->surnameSignal($pair->authors, $reference->authors);

        // A year alone cannot identify a bibliography entry.
        if ($surnames === null) {
            return null;
        }

        $year = $this->yearSignal($pair->year, $reference->publicationYear);

        $weightSum = 0.0;
        $sum = 0.0;

        $sum += $weights['surnames'] * $surnames;
        $weightSum += $weights['surnames'];

        if ($year !== null) {
            $sum += $weights['year'] * $year;
            $weightSum += $weights['year'];
        }

        if ($weightSum <= 0.0) {
            return null;
        }

        return [
            'confidence' => max(0.0, min(1.0, $sum / $weightSum)),
            'reason' => $this->reason($surnames, $year),
        ];
    }

    /**
     * Best-pair average surname similarity.
     *
     * The denominator is the number of **cited** authors, so a cited author the
     * reference does not carry lowers the score; a truncated `et al.` citation
     * with fewer authors than the reference is not penalised.
     *
     * @param  list<AuthorName>  $cited
     * @param  list<AuthorName>  $reference
     */
    private function surnameSignal(array $cited, array $reference): ?float
    {
        if ($cited === [] || $reference === []) {
            return null;
        }

        $used = [];
        $scores = [];

        foreach ($cited as $citedName) {
            $best = null;
            $bestIndex = null;

            foreach ($reference as $index => $referenceName) {
                if (isset($used[$index])) {
                    continue;
                }

                $similarity = $this->strings->jaroWinkler($citedName->surname, $referenceName->surname) ?? 0.0;

                if ($best === null || $similarity > $best) {
                    $best = $similarity;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                continue;
            }

            $used[$bestIndex] = true;
            $scores[] = max(0.0, $best - $this->initialPenalty($citedName, $reference[$bestIndex]));
        }

        if ($scores === []) {
            return null;
        }

        return max(0.0, min(1.0, array_sum($scores) / count($cited)));
    }

    private function initialPenalty(AuthorName $cited, AuthorName $reference): float
    {
        $citedInitial = $cited->firstInitial();
        $referenceInitial = $reference->firstInitial();

        if ($citedInitial === null || $referenceInitial === null || $citedInitial === $referenceInitial) {
            return 0.0;
        }

        return $this->config->initialPenalty();
    }

    /**
     * Graded year signal; null when either side has no year (renormalized).
     */
    private function yearSignal(?int $citedYear, ?int $referenceYear): ?float
    {
        if ($citedYear === null || $referenceYear === null) {
            return null;
        }

        return max(0.0, 1.0 - (abs($citedYear - $referenceYear) / $this->config->yearWindow()));
    }

    private function reason(?float $surnames, ?float $year): string
    {
        $parts = [];

        if ($surnames !== null) {
            $parts[] = sprintf('kemiripan nama %.2f', $surnames);
        }

        if ($year !== null) {
            $parts[] = $year >= 1.0 ? 'tahun cocok' : sprintf('kesesuaian tahun %.2f', $year);
        }

        return $parts === [] ? 'Tidak ada sinyal yang tersedia.' : ucfirst(implode('; ', $parts)).'.';
    }
}
