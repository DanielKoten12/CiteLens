<?php

namespace App\Services\Scoring;

use App\Enums\ReferenceFindingStatus;
use App\Services\Crossref\DoiLookup;

/**
 * The single implementation of the verification decision matrix
 * (`docs/API_SPEC.md` §2.6 + OQ-02).
 *
 * Inputs are pure: the DOI lookup outcome, the transient-failure flag and the
 * ranked candidates. The output is a {@see ReferenceVerdict}; no other class
 * maps these inputs to a status.
 */
final class VerdictDecider
{
    public function __construct(
        private readonly ScoringConfig $config,
        private readonly LocalVenueDetector $localVenue,
        private readonly MatchReasonBuilder $reasons,
    ) {}

    /**
     * @param  list<ScoredCandidate>  $ranked  best-first
     */
    public function decide(
        ScoringReference $reference,
        DoiLookup $doiLookup,
        bool $transientFailure,
        array $ranked,
    ): ReferenceVerdict {
        if ($transientFailure) {
            return $this->verdict($reference, ReferenceFindingStatus::Pending, null, MatchReasonBuilder::TRANSIENT, null, $ranked);
        }

        if ($doiLookup === DoiLookup::Malformed) {
            return $this->verdict($reference, ReferenceFindingStatus::Invalid, null, MatchReasonBuilder::MALFORMED_DOI, null, $ranked);
        }

        if ($doiLookup === DoiLookup::NotFound) {
            $best = $ranked[0] ?? null;

            return $this->verdict($reference, ReferenceFindingStatus::Invalid, null, MatchReasonBuilder::DOI_NOT_FOUND, $best?->rank, $ranked);
        }

        if ($doiLookup === DoiLookup::Resolved) {
            return $this->decideDoiResolved($reference, $ranked);
        }

        return $this->decideWithoutDoi($reference, $ranked);
    }

    /**
     * @param  list<ScoredCandidate>  $ranked
     */
    private function decideDoiResolved(ScoringReference $reference, array $ranked): ReferenceVerdict
    {
        $doiCandidate = null;

        foreach ($ranked as $candidate) {
            if ($candidate->breakdown->doiMatch) {
                $doiCandidate = $candidate;

                break;
            }
        }

        if ($doiCandidate !== null && ! $doiCandidate->breakdown->hasConflicts()) {
            return $this->verdict(
                $reference,
                ReferenceFindingStatus::Valid,
                $doiCandidate->confidence(),
                MatchReasonBuilder::DOI_MATCH,
                $doiCandidate->rank,
                $ranked,
            );
        }

        // DOI resolved but the metadata disagrees: the document's DOI is wrong.
        // Confidence records the evidence for the verdict, while the selected
        // candidate points at the best alternative (the suggested correct work).
        $best = $ranked[0] ?? $doiCandidate;
        $confidence = $doiCandidate?->confidence() ?? $best?->confidence();

        return $this->verdict(
            $reference,
            ReferenceFindingStatus::Invalid,
            $confidence,
            $this->reasons->forDoiConflict($doiCandidate?->breakdown->conflicts ?? []),
            $best?->rank,
            $ranked,
        );
    }

    /**
     * @param  list<ScoredCandidate>  $ranked
     */
    private function decideWithoutDoi(ScoringReference $reference, array $ranked): ReferenceVerdict
    {
        if ($ranked === []) {
            if ($this->localVenue->looksLocal($reference, null)) {
                return $this->verdict($reference, ReferenceFindingStatus::Suspicious, null, MatchReasonBuilder::LOCAL_VENUE, null, []);
            }

            return $this->verdict($reference, ReferenceFindingStatus::NotFound, null, MatchReasonBuilder::NOT_FOUND, null, []);
        }

        $best = $ranked[0];
        $confidence = $best->confidence();

        if ($confidence >= $this->config->validThreshold()) {
            return $this->verdict(
                $reference,
                ReferenceFindingStatus::Valid,
                $confidence,
                $this->reasons->forNoDoiValid($best),
                $best->rank,
                $ranked,
            );
        }

        if ($confidence >= $this->config->suspiciousThreshold()) {
            return $this->verdict($reference, ReferenceFindingStatus::Suspicious, $confidence, MatchReasonBuilder::SUSPICIOUS, $best->rank, $ranked);
        }

        if ($this->localVenue->looksLocal($reference, $best)) {
            return $this->verdict($reference, ReferenceFindingStatus::Suspicious, null, MatchReasonBuilder::LOCAL_VENUE, $best->rank, $ranked);
        }

        return $this->verdict($reference, ReferenceFindingStatus::NotFound, null, MatchReasonBuilder::NOT_FOUND, null, $ranked);
    }

    /**
     * @param  list<ScoredCandidate>  $candidates
     */
    private function verdict(
        ScoringReference $reference,
        ReferenceFindingStatus $status,
        ?float $confidence,
        ?string $reason,
        ?int $selectedRank,
        array $candidates,
    ): ReferenceVerdict {
        return new ReferenceVerdict(
            referenceId: $reference->id,
            status: $status,
            confidence: $confidence === null ? null : round(max(0.0, min(1.0, $confidence)), 4),
            reason: $reason,
            selectedRank: $selectedRank,
            candidates: $candidates,
        );
    }
}
