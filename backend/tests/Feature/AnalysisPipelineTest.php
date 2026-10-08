<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalysisStepRegistry;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use App\Services\Analysis\Steps\ExtractDocumentStep;
use App\Services\Analysis\Steps\FinalizeAnalysisStep;
use App\Services\Analysis\Steps\PersistExtractionStep;
use App\Services\Document\DocumentAnalysisStateService;
use Tests\Support\AnalysisHarness;
use Tests\Support\CrossrefFake;
use Tests\Support\DocumentTree;
use Tests\Support\InferenceFake;
use Tests\Support\StubPipelineStep;

it('binds the pipeline to the analysis seam', function () {
    expect(app(RunsDocumentAnalysis::class))->toBeInstanceOf(AnalysisPipeline::class);
});

it('runs registered steps in canonical order regardless of registration order', function () {
    $order = [];

    $steps = [];

    foreach ([AnalysisStep::Extracting, AnalysisStep::Persisting, AnalysisStep::CrossrefValidation, AnalysisStep::Scoring] as $step) {
        $steps[] = StubPipelineStep::for($step, function () use (&$order, $step): void {
            $order[] = $step->value;
        });
    }

    AnalysisHarness::useSteps(array_reverse($steps));

    app(AnalysisPipeline::class)->run(DocumentTree::create()->document);

    expect($order)->toBe(['extracting', 'persisting', 'crossref_validation', 'scoring']);
});

it('rejects duplicate and unknown steps', function () {
    $duplicate = new AnalysisStepRegistry([
        StubPipelineStep::for(AnalysisStep::Extracting),
        StubPipelineStep::for(AnalysisStep::Extracting),
    ]);

    expect(fn () => $duplicate->ordered())->toThrow(InvalidArgumentException::class);
});

it('completes a document through the real extraction, crossref and scoring steps', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_progress->toBe(100)
        ->analysis_step->toBe(AnalysisStep::Completed)
        ->analysis_error->toBeNull()
        ->analysis_started_at->not->toBeNull()
        ->analysis_completed_at->not->toBeNull();

    expect(ResearchedDocumentReference::query()->count())->toBe(3)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(3)
        ->and(ReferenceFinding::query()->count())->toBe(3);
});

it('keeps progress monotonic and reaches 100 only on completion', function () {
    $seen = [];

    $capture = function (ResearchedDocument $document) use (&$seen): void {
        $seen[] = $document->fresh()->analysis_progress;
    };

    AnalysisHarness::useSteps([
        StubPipelineStep::for(AnalysisStep::Extracting, $capture),
        StubPipelineStep::for(AnalysisStep::Persisting, $capture),
        StubPipelineStep::for(AnalysisStep::CrossrefValidation, $capture),
        StubPipelineStep::for(AnalysisStep::GeneratingReport, $capture),
    ]);

    $document = DocumentTree::create()->document;

    app(AnalysisPipeline::class)->run($document);

    $sorted = $seen;
    sort($sorted);

    expect($seen)->toBe($sorted)
        ->and($seen)->toBe([5, 25, 35, 95])
        ->and($document->refresh()->analysis_progress)->toBe(100);
});

it('fails safely when the inference service is unavailable', function () {
    InferenceFake::unavailable();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Layanan analisis tidak tersedia. Coba lagi nanti.');
});

it('fails safely when the inference service cannot be reached', function () {
    InferenceFake::connectionError();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Layanan analisis tidak tersedia. Coba lagi nanti.');
});

it('fails safely when the PDF cannot be parsed', function () {
    InferenceFake::unparseablePdf();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.');
});

it('fails safely when the extraction payload violates the contract', function () {
    InferenceFake::malformedExtraction();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.');
});

it('recovers from a partial failure on a clean re-run', function () {
    InferenceFake::extraction();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps([
        app(ExtractDocumentStep::class),
        app(PersistExtractionStep::class),
        StubPipelineStep::for(AnalysisStep::CrossrefValidation, fn () => throw new RuntimeException('boom')),
        app(FinalizeAnalysisStep::class),
    ]);

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and(ResearchedDocumentReference::query()->count())->toBe(3);

    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and(ResearchedDocumentReference::query()->count())->toBe(3)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(3)
        ->and(ReferenceFinding::query()->count())->toBe(3);
});

it('aborts quietly when the document is deleted mid-run', function () {
    InferenceFake::extraction();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps([
        app(ExtractDocumentStep::class),
        app(PersistExtractionStep::class),
        StubPipelineStep::for(AnalysisStep::CrossrefValidation, function (ResearchedDocument $document): void {
            $document->delete();

            throw new RuntimeException('gone');
        }),
        app(FinalizeAnalysisStep::class),
    ]);

    app(AnalysisPipeline::class)->run($tree->document);

    expect(ResearchedDocument::query()->whereKey($tree->document->getKey())->exists())->toBeFalse();
});

it('starts a document resumed from a failure without decreasing progress', function () {
    $document = ResearchedDocument::factory()->failed()->create(['analysis_progress' => 60]);

    AnalysisHarness::useSteps([
        StubPipelineStep::for(AnalysisStep::CrossrefValidation),
        app(FinalizeAnalysisStep::class),
    ]);

    app(AnalysisPipeline::class)->run($document);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_progress->toBe(100);
});

it('is idempotent when the pipeline runs twice on the same document', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalysisHarness::useSteps(AnalysisHarness::fullSteps());

    app(AnalysisPipeline::class)->run($tree->document);
    app(DocumentAnalysisStateService::class)->resetToQueued($tree->document);
    app(AnalysisPipeline::class)->run($tree->document);

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and(ResearchedDocumentReference::query()->count())->toBe(3)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(3)
        ->and(ReferenceFinding::query()->count())->toBe(3);
});
