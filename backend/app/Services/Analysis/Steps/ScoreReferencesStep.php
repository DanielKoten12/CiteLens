<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\ReferenceFinding\ReferenceFindingWriter;
use App\Services\Scoring\ReferenceScorer;
use App\Services\Scoring\ScoredCandidate;
use App\Services\Scoring\VerdictDecider;

/**
 * `scoring` — decides every reference verdict and persists it.
 *
 * This is the single place that turns a {@see ReferenceVerificationBatch} plus
 * an `EmbeddingIndex` into `reference_findings` /
 * `reference_finding_candidates` rows: it scores, applies the decision matrix
 * through {@see VerdictDecider}, then writes once per document through
 * {@see ReferenceFindingWriter}.
 */
final class ScoreReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly ReferenceScorer $scorer,
        private readonly VerdictDecider $decider,
        private readonly ReferenceFindingWriter $writer,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::Scoring;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $batch = $context->takeVerification();
        $embeddings = $context->embeddings();

        $verdicts = [];
        $total = $batch->count();

        foreach ($batch->verifications as $index => $verification) {
            $ranked = $this->scorer->score($verification->reference, $verification->candidates, $embeddings);
            $ranked = $this->cap($ranked);

            $verdicts[] = $this->decider->decide(
                $verification->reference,
                $verification->doiLookup,
                $verification->transientFailure,
                $ranked,
                $verification->pendingReason,
                $verification->doiSource,
            );

            $this->progress->report($document, $this->step(), $index + 1, $total);
        }

        $this->writer->persistBatch($document, $verdicts);
    }

    /**
     * Bound the persisted candidate list after ranking (D-09-09).
     *
     * `0` means unlimited. Ranks stay `1..N` and contiguous, so
     * `selected_candidate_id` always resolves.
     *
     * @param  list<ScoredCandidate>  $ranked
     * @return list<ScoredCandidate>
     */
    private function cap(array $ranked): array
    {
        $limit = max(0, (int) config('analysis.metadata.max_candidates_per_reference', 10));

        return $limit === 0 ? $ranked : array_slice($ranked, 0, $limit);
    }
}
