<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use App\Services\Document\DocumentAnalysisStateService;

beforeEach(function () {
    $this->service = app(DocumentAnalysisStateService::class);
});

it('starts a pending document once, keeping the original start time', function () {
    $document = ResearchedDocument::factory()->pending()->create();

    $this->service->start($document);
    $startedAt = $document->refresh()->analysis_started_at;

    expect($document->status)->toBe(DocumentStatus::Processing)
        ->and($startedAt)->not->toBeNull();

    $this->travel(2)->minutes();
    $this->service->start($document->refresh());

    expect($document->refresh()->analysis_started_at->equalTo($startedAt))->toBeTrue();
});

it('advances progress monotonically within bounds', function () {
    $document = ResearchedDocument::factory()->processing()->create(['analysis_progress' => 40]);

    $this->service->advance($document, AnalysisStep::Scoring, 70);
    expect($document->refresh())
        ->analysis_progress->toBe(70)
        ->analysis_step->toBe(AnalysisStep::Scoring);

    // A lower value never moves progress backwards.
    $this->service->advance($document->refresh(), AnalysisStep::Embedding, 30);
    expect($document->refresh()->analysis_progress)->toBe(70);

    // Values are clamped to 0..100.
    $this->service->advance($document->refresh(), AnalysisStep::Scoring, 250);
    expect($document->refresh()->analysis_progress)->toBe(100);
});

it('does not advance a terminal document', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    $this->service->advance($document, AnalysisStep::Scoring, 10);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_progress->toBe(100);
});

it('completes a document with a clean terminal state', function () {
    $document = ResearchedDocument::factory()->processing()->create([
        'analysis_error' => 'stale error',
    ]);

    $this->service->complete($document);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_progress->toBe(100)
        ->analysis_step->toBe(AnalysisStep::Completed)
        ->analysis_error->toBeNull()
        ->and($document->analysis_completed_at)->not->toBeNull();
});

it('resets a document back to the queued state', function () {
    $document = ResearchedDocument::factory()->failed()->create();

    $this->service->resetToQueued($document);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Pending)
        ->analysis_progress->toBe(0)
        ->analysis_step->toBe(AnalysisStep::Queued)
        ->analysis_error->toBeNull()
        ->and($document->analysis_started_at)->toBeNull()
        ->and($document->analysis_completed_at)->toBeNull();
});

it('fails a non-terminal document with a safe message', function () {
    $document = ResearchedDocument::factory()->processing()->create(['analysis_progress' => 55]);

    $this->service->fail($document->getKey(), 'Analisis dokumen gagal. Silakan coba lagi.');

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_progress->toBe(55)
        ->analysis_error->toBe('Analisis dokumen gagal. Silakan coba lagi.');
});

it('never overwrites a terminal document and tolerates a missing id', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    $this->service->fail($document->getKey(), 'late failure');
    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_error->toBeNull();

    // Missing id is a quiet no-op.
    $this->service->fail(fake()->uuid(), 'missing');

    expect(true)->toBeTrue();
});
