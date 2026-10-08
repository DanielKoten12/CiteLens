<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStep;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ResearchedDocument;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use App\Services\Document\DocumentAnalysisStateService;
use Throwable;

/**
 * Runs the canonical analysis steps and drives the document to a terminal state.
 *
 * Owns the run lifecycle: the `processing` transition, per-step progress, the
 * `completed` transition and the failure transition. Steps never touch progress
 * columns or catch their own fatal errors.
 *
 * A step failure is handled here ({@see AnalysisFailureHandler} logs the raw
 * exception and persists a safe message) and is deliberately **not** rethrown:
 * the document's `failed` state is the record, and
 * {@see AnalyzeDocumentJob::failed()} remains the worker-level safety
 * net for timeouts, kills and unresolvable dependencies.
 */
final class AnalysisPipeline implements RunsDocumentAnalysis
{
    public function __construct(
        private readonly DocumentAnalysisStateService $state,
        private readonly AnalysisProgress $progress,
        private readonly AnalysisFailureHandler $failureHandler,
        private readonly AnalysisStepRegistry $registry,
    ) {}

    public function run(ResearchedDocument $document): void
    {
        $context = new AnalysisContext;
        $current = AnalysisStep::Queued;

        try {
            $this->state->start($document);
            $this->progress->begin($document);

            foreach ($this->registry->ordered() as $step) {
                $current = $step->step();

                $this->progress->enter($document, $current);
                $step->handle($document, $context);
                $this->progress->leave($document, $current);
            }

            $this->progress->complete($document);
        } catch (Throwable $exception) {
            // Deleted mid-run: nothing to transition, no exception noise (F-PIPE-02).
            if (! ResearchedDocument::query()->whereKey($document->getKey())->exists()) {
                return;
            }

            $this->failureHandler->handle($document, $exception, $current);
        }
    }
}
