<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Metadata\DoiLookup;
use App\Services\Metadata\DoiNormalizer;
use App\Services\Metadata\DoiResolutionChain;
use App\Services\Metadata\MetadataSourceChain;
use App\Services\Metadata\ReferenceQuery;
use App\Services\Metadata\SourceChainState;
use App\Services\Scoring\MatchReasonBuilder;
use App\Services\Scoring\ScoringReference;

/**
 * `crossref_validation` — retrieves candidate metadata for every reference.
 *
 * Performs network I/O only: durable rows are written by `ScoreReferencesStep`
 * after scoring (D-04-05). References are processed in bibliography order
 * (`text_start_offset` ASC, nulls last, `id` tiebreak — OQ-18).
 *
 * This step is a thin orchestrator (D-09-05): the ordered DOI resolution chain
 * (Crossref → Semantic Scholar → OpenAlex) and the ordered search chain
 * (Crossref → Garuda → Semantic Scholar → OpenAlex) own their own gating,
 * budgets, outage policies and merging. No per-source `if` lives here.
 */
final class ValidateReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly DoiResolutionChain $doiChain,
        private readonly MetadataSourceChain $sourceChain,
        private readonly DoiNormalizer $normalizer,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::CrossrefValidation;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $references = $document->references()
            ->orderByRaw('text_start_offset IS NULL')
            ->orderBy('text_start_offset')
            ->orderBy('id')
            ->get();

        $state = new SourceChainState(
            documentId: (string) $document->getKey(),
            fallbackBudget: max(0, (int) config('analysis.metadata.fallback.max_requests_per_document', 300)),
            fallbackSeconds: max(0, (int) config('analysis.metadata.fallback.max_seconds', 300)),
        );

        $verifications = [];
        $total = $references->count();

        foreach ($references as $index => $reference) {
            $verifications[] = $this->verifyReference($reference, $state);

            $this->progress->report($document, $this->step(), $index + 1, $total);
        }

        $context->setVerification(new ReferenceVerificationBatch($verifications));
    }

    private function verifyReference(
        ResearchedDocumentReference $reference,
        SourceChainState $state,
    ): ReferenceVerification {
        $referenceValue = ScoringReference::fromModel($reference, $this->normalizer);
        $referenceId = (string) $reference->getKey();

        $doiLookup = DoiLookup::NotPresent;
        $doiSource = null;
        $candidates = [];
        $transientFailure = false;
        $pendingReason = null;

        if ($referenceValue->doi !== null) {
            if (! $referenceValue->doiValid) {
                $doiLookup = DoiLookup::Malformed;
            } else {
                $result = $this->doiChain->resolve($referenceValue->doi, $state, $referenceId);

                $doiLookup = $result->lookup;
                $doiSource = $result->source;

                if ($result->work !== null) {
                    $candidates[] = $result->work;
                }

                if ($result->lookup === DoiLookup::Unknown) {
                    $transientFailure = true;
                    $pendingReason = $result->pendingReason ?? MatchReasonBuilder::DOI_UNKNOWN;
                }
            }
        }

        // Best-effort bibliographic search whenever there is data to search on
        // (D-04-02): it supplies the correct-publication candidate for a
        // conflicting/non-resolving DOI and the candidates for a no-DOI entry.
        $searchQuery = ReferenceQuery::fromReference($reference);

        if ($searchQuery->bibliographic !== '') {
            $result = $this->sourceChain->search(
                query: $searchQuery,
                referenceTitle: $referenceValue->title,
                existing: $candidates,
                state: $state,
                referenceId: $referenceId,
            );

            $candidates = $result->candidates;
            $transientFailure = $transientFailure || $result->transientFailure;
            $pendingReason = $pendingReason ?? $result->pendingReason;
        }

        return new ReferenceVerification(
            reference: $referenceValue,
            doiLookup: $doiLookup,
            candidates: $candidates,
            transientFailure: $transientFailure,
            pendingReason: $pendingReason,
            doiSource: $doiSource,
        );
    }
}
