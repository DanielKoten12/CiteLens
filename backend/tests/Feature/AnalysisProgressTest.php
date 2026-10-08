<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisProgress;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->progress = app(AnalysisProgress::class);
});

it('enters a step at its floor and leaves it at its ceiling', function () {
    $document = DocumentTree::create()->document;

    $this->progress->enter($document, AnalysisStep::Extracting);
    expect($document->refresh()->analysis_progress)->toBe(5)
        ->and($document->analysis_step)->toBe(AnalysisStep::Extracting);

    $this->progress->leave($document, AnalysisStep::Extracting);
    expect($document->refresh()->analysis_progress)->toBe(25);
});

it('interpolates intra-step progress and never moves backwards', function () {
    $document = DocumentTree::create()->document;

    $this->progress->enter($document, AnalysisStep::Extracting);

    $this->progress->report($document, AnalysisStep::Extracting, 1, 2);
    expect($document->refresh()->analysis_progress)->toBe(15);

    $this->progress->report($document, AnalysisStep::Extracting, 3, 4);
    expect($document->refresh()->analysis_progress)->toBe(20);

    // Out-of-order (lower) reports are ignored by the write cache.
    $this->progress->report($document, AnalysisStep::Extracting, 1, 4);
    expect($document->refresh()->analysis_progress)->toBe(20);

    // A report beyond the total is clamped to the ceiling, never past it.
    $this->progress->report($document, AnalysisStep::Extracting, 99, 4);
    expect($document->refresh()->analysis_progress)->toBe(25);
});

it('ignores a report with no items', function () {
    $document = DocumentTree::create()->document;

    $this->progress->enter($document, AnalysisStep::Extracting);
    $this->progress->report($document, AnalysisStep::Extracting, 0, 0);

    expect($document->refresh()->analysis_progress)->toBe(5);
});

it('fails fast when a step has no configured range', function () {
    config()->set('analysis.progress.extracting', null);

    $document = DocumentTree::create()->document;

    expect(fn () => $this->progress->enter($document, AnalysisStep::Extracting))
        ->toThrow(InvalidArgumentException::class);
});

it('only reaches 100 through complete', function () {
    $document = DocumentTree::create()->document;

    $this->progress->enter($document, AnalysisStep::GeneratingReport);
    $this->progress->leave($document, AnalysisStep::GeneratingReport);

    expect($document->refresh()->analysis_progress)->toBe(99)
        ->and($document->status)->not->toBe(DocumentStatus::Completed);

    $this->progress->complete($document);

    expect($document->refresh())
        ->analysis_progress->toBe(100)
        ->analysis_step->toBe(AnalysisStep::Completed)
        ->status->toBe(DocumentStatus::Completed);
});

it('does not move a completed document backwards', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    $this->progress->enter($document, AnalysisStep::Queued);
    $this->progress->leave($document, AnalysisStep::Queued);

    expect($document->refresh())
        ->analysis_progress->toBe(100)
        ->analysis_step->toBe(AnalysisStep::Completed);
});
