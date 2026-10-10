<?php

namespace App\Services\Citations;

use App\Enums\CitationResolutionState;

/**
 * Resolves a whole document's citations in two passes (D-05.1-06).
 *
 * Pass 1 scores and decides every citation independently (see
 * {@see CitationResolver}). Pass 2 consolidates evidence: when a citation cannot
 * commit because it has no year (e.g. a typo'd `(Hartini)`), but its best
 * candidate reference is already paired with a sibling citation that carries a
 * year (`(Hartono, 2023)`), that year is propagated and the citation is
 * re-scored. Consolidation only upgrades `unmatched` → `paired`; a pair
 * committed in pass 1 is never weakened.
 *
 * Citations → references has unlimited capacity, so this is not bipartite
 * assignment: the second pass adds recall through evidence propagation, not
 * capacity arbitration.
 */
final class CitationBatchResolver
{
    public function __construct(
        private readonly CitationResolver $resolver,
    ) {}

    /**
     * @param  list<CitationReference>  $references
     * @param  list<CitationResolutionInput>  $inputs
     * @return array<string, CitationResolution> keyed by citation id
     */
    public function resolve(array $references, array $inputs): array
    {
        $resolutions = [];

        foreach ($inputs as $input) {
            $resolutions[$input->citationId] = $this->resolver->resolve(
                $input->marker,
                $references,
                $input->hintedReferenceId,
                $input->hintIndex,
            );
        }

        $this->consolidate($references, $inputs, $resolutions);

        return $resolutions;
    }

    /**
     * @param  list<CitationReference>  $references
     * @param  list<CitationResolutionInput>  $inputs
     * @param  array<string, CitationResolution>  $resolutions
     */
    private function consolidate(array $references, array $inputs, array &$resolutions): void
    {
        $yearsByReference = [];

        foreach ($inputs as $input) {
            $resolution = $resolutions[$input->citationId] ?? null;

            if ($resolution?->state !== CitationResolutionState::Paired || $resolution->referenceId === null) {
                continue;
            }

            $year = $input->marker->primaryPair()?->year;

            if ($year !== null) {
                $yearsByReference[$resolution->referenceId][$year] = true;
            }
        }

        foreach ($inputs as $input) {
            $resolution = $resolutions[$input->citationId] ?? null;

            if ($resolution?->state !== CitationResolutionState::Unmatched
                && $resolution?->state !== CitationResolutionState::Unresolved) {
                continue;
            }

            $pair = $input->marker->primaryPair();

            if ($pair === null || $pair->year !== null) {
                continue;
            }

            $best = $resolution->candidates[0] ?? null;

            if ($best === null) {
                continue;
            }

            $years = array_keys($yearsByReference[$best->referenceId] ?? []);

            if (count($years) !== 1) {
                continue;
            }

            $candidate = $this->resolver->resolve(
                new ParsedCitationMarker([$pair->mergedWith((int) $years[0])]),
                $references,
            );

            if ($candidate->isPaired()) {
                $resolutions[$input->citationId] = $candidate;
            }
        }
    }
}
