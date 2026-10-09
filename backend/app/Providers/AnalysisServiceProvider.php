<?php

namespace App\Providers;

use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisProgress;
use App\Services\Analysis\AnalysisStepRegistry;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use App\Services\Analysis\Steps\EmbedReferencesStep;
use App\Services\Analysis\Steps\ExtractDocumentStep;
use App\Services\Analysis\Steps\FinalizeAnalysisStep;
use App\Services\Analysis\Steps\PersistExtractionStep;
use App\Services\Analysis\Steps\ResolveCitationsStep;
use App\Services\Analysis\Steps\ScoreReferencesStep;
use App\Services\Analysis\Steps\ValidateReferencesStep;
use App\Services\Scoring\ScoringConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the document-analysis pipeline.
 *
 * The step list is the only place that changes when Phases 04/05 land: append
 * their steps below (the registry sorts by the canonical order and rejects
 * duplicates). The pipeline is bound transiently so each run gets a fresh
 * progress write cache.
 */
final class AnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One progress reporter per job/run: queue workers flush scoped instances
        // after every job, and `AnalysisProgress::begin()` resets its write cache,
        // so the pipeline and its steps share exactly one instance per run (D-04-06).
        $this->app->scoped(AnalysisProgress::class);

        $this->app->singleton(ScoringConfig::class);

        $this->app->singleton(AnalysisStepRegistry::class, fn (Application $app): AnalysisStepRegistry => new AnalysisStepRegistry([
            $app->make(ExtractDocumentStep::class),
            $app->make(PersistExtractionStep::class),
            $app->make(ValidateReferencesStep::class),
            $app->make(EmbedReferencesStep::class),
            $app->make(ScoreReferencesStep::class),
            $app->make(ResolveCitationsStep::class),   // resolving_citations (Phase 05)
            $app->make(FinalizeAnalysisStep::class),
        ]));

        $this->app->bind(RunsDocumentAnalysis::class, AnalysisPipeline::class);
    }
}
