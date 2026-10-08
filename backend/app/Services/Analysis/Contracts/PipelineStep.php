<?php

namespace App\Services\Analysis\Contracts;

use App\Enums\AnalysisStep;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\AnalysisPipeline;

/**
 * One step of the canonical analysis pipeline (`docs/API_SPEC.md` §10).
 *
 * A step is a narrow unit of work plus its canonical label. The runner
 * ({@see AnalysisPipeline}) owns ordering, progress and failure handling, so a
 * step never touches progress columns or catches its own fatal errors.
 */
interface PipelineStep
{
    /**
     * The canonical step this handler implements.
     */
    public function step(): AnalysisStep;

    /**
     * Execute the step.
     *
     * Durable state is written to the database; transient artifacts travel on the
     * shared {@see AnalysisContext}. A thrown exception is fatal for the run and
     * is mapped to a safe user-facing error by the failure handler.
     */
    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
