<?php

namespace App\Providers;

use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStepRegistry;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use App\Services\Analysis\Steps\ExtractDocumentStep;
use App\Services\Analysis\Steps\FinalizeAnalysisStep;
use App\Services\Analysis\Steps\PersistExtractionStep;
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
        $this->app->singleton(AnalysisStepRegistry::class, fn (Application $app): AnalysisStepRegistry => new AnalysisStepRegistry([
            $app->make(ExtractDocumentStep::class),
            $app->make(PersistExtractionStep::class),
            // Phase 04 appends: ValidateReferencesStep, EmbedReferencesStep, ScoreReferencesStep.
            // Phase 05 appends: ResolveCitationsStep.
            $app->make(FinalizeAnalysisStep::class),
        ]));

        $this->app->bind(RunsDocumentAnalysis::class, AnalysisPipeline::class);
    }
}
