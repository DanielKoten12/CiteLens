<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;

/**
 * Pairs one parsed citation marker with at most one reference of the same
 * document (Phase 05.1).
 *
 * Decision layers, in order:
 *
 * 1. **IEEE ordinals** map deterministically to bibliography positions (OQ-18);
 *    the lowest valid ordinal is the primary pairing, the rest become candidates
 *    (D-05.1-07).
 * 2. **Extraction hint** (validated): when GROBID linked the citation to a
 *    bibliography entry, that link is a strong prior. An unparseable marker
 *    trusts it outright; a parseable marker trusts it unless the scored
 *    candidates plausibly contradict it, in which case the parser wins and the
 *    hint stays provenance (D-05.1-03). GROBID provides no confidence, so a
 *    hint-only pairing records `confidence = null`.
 * 3. **APA scoring**: the best candidate at or above `commit_threshold` is
 *    committed; otherwise the citation is left `unmatched` with candidates.
 *
 * The class is pure and only receives references of one document, so it can
 * never pair across documents. Ambiguity handling (`unresolved` + `winner_margin`)
 * lands with the persisted resolution state in W2 so W1 never drops a pair that
 * Phase 05 would have committed (D-05.1-06).
 */
final class CitationResolver
{
    public function __construct(
        private readonly CitationMatchConfig $config,
        private readonly CitationCandidateScorer $scorer,
        private readonly CitationDecisionPolicy $policy,
    ) {}

    /**
     * @param  list<CitationReference>  $references  bibliography order (index 1..N)
     */
    public function resolve(
        ParsedCitationMarker $marker,
        array $references,
        ?string $hintedReferenceId = null,
        ?int $hintIndex = null,
    ): CitationResolution {
        if ($marker->isIeee()) {
            return $this->resolveIeee($marker, $references, $hintIndex);
        }

        return $this->resolveApa($marker, $references, $hintedReferenceId, $hintIndex);
    }

    /**
     * @param  list<CitationReference>  $references
     */
    private function resolveIeee(
        ParsedCitationMarker $marker,
        array $references,
        ?int $hintIndex,
    ): CitationResolution {
        $byIndex = [];

        foreach ($references as $reference) {
            $byIndex[$reference->bibliographyIndex] = $reference;
        }

        $matched = [];

        foreach ($marker->ordinals as $ordinal) {
            if (isset($byIndex[$ordinal])) {
                $matched[$ordinal] = $byIndex[$ordinal];
            }
        }

        if ($matched === []) {
            return CitationResolution::unmatched($hintIndex);
        }

        $candidates = [];
        $primary = null;
        $rank = 1;

        foreach ($matched as $ordinal => $reference) {
            $primary ??= $reference;
            $candidates[] = new CitationCandidate(
                referenceId: $reference->id,
                confidence: 1.0,
                method: CitationResolutionMethod::Ieee,
                matchReason: sprintf('Nomor IEEE [%d].', $ordinal),
                rank: $rank,
            );
            $rank++;
        }

        return CitationResolution::paired(
            $primary->id,
            1.0,
            CitationResolutionMethod::Ieee,
            $hintIndex,
            $candidates,
        );
    }

    /**
     * @param  list<CitationReference>  $references
     */
    private function resolveApa(
        ParsedCitationMarker $marker,
        array $references,
        ?string $hintedReferenceId,
        ?int $hintIndex,
    ): CitationResolution {
        $primaryPair = $marker->primaryPair();
        $scored = $primaryPair === null ? [] : $this->scorer->score($primaryPair, $references);

        if ($this->config->trustExtractionHint() && $hintedReferenceId !== null) {
            $hinted = $this->findReference($references, $hintedReferenceId);

            if ($hinted !== null) {
                return $this->resolveWithHint($primaryPair, $scored, $hinted, $hintIndex);
            }
        }

        return $this->resolveFromCandidates($scored, $hintIndex);
    }

    /**
     * @param  list<CitationCandidate>  $scored
     */
    private function resolveWithHint(
        ?ParsedAuthorYear $pair,
        array $scored,
        CitationReference $hinted,
        ?int $hintIndex,
    ): CitationResolution {
        if ($pair === null) {
            // No contradicting signal: trust the extractor's own link.
            return CitationResolution::paired(
                $hinted->id,
                null,
                CitationResolutionMethod::ExtractionHint,
                $hintIndex,
                $scored,
            );
        }

        $hintedCandidate = $this->candidateFor($scored, $hinted->id);
        $best = $scored[0] ?? null;

        $hintIsBest = $best !== null && $best->referenceId === $hinted->id;
        $hintPlausible = $hintedCandidate !== null && $hintedCandidate->confidence >= $this->config->proposalThreshold();

        if ($hintIsBest || $hintPlausible || $best === null) {
            return CitationResolution::paired(
                $hinted->id,
                $hintedCandidate?->confidence,
                CitationResolutionMethod::ExtractionHint,
                $hintIndex,
                $scored,
            );
        }

        // The parser contradicts the hint; the parser result decides.
        return $this->decideFromCandidates($scored, $hintIndex);
    }

    /**
     * @param  list<CitationCandidate>  $scored
     */
    private function resolveFromCandidates(array $scored, ?int $hintIndex): CitationResolution
    {
        return $this->decideFromCandidates($scored, $hintIndex);
    }

    /**
     * @param  list<CitationCandidate>  $scored
     */
    private function decideFromCandidates(array $scored, ?int $hintIndex): CitationResolution
    {
        $best = $scored[0] ?? null;

        return match ($this->policy->stateFor($scored)) {
            CitationResolutionState::Paired => CitationResolution::paired(
                $best->referenceId,
                $best->confidence,
                CitationResolutionMethod::Apa,
                $hintIndex,
                $scored,
            ),
            CitationResolutionState::Unresolved => CitationResolution::unresolved(
                $best?->confidence,
                CitationResolutionMethod::Apa,
                $hintIndex,
                $scored,
            ),
            CitationResolutionState::Unmatched => CitationResolution::unmatched($hintIndex, $scored),
        };
    }

    /**
     * @param  list<CitationReference>  $references
     */
    private function findReference(array $references, string $referenceId): ?CitationReference
    {
        foreach ($references as $reference) {
            if ($reference->id === $referenceId) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     */
    private function candidateFor(array $candidates, string $referenceId): ?CitationCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->referenceId === $referenceId) {
                return $candidate;
            }
        }

        return null;
    }
}
