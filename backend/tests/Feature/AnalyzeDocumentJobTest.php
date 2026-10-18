<?php

use App\Enums\DocumentStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ResearchedDocument;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use Illuminate\Queue\Middleware\WithoutOverlapping;

it('serializes only the document id and reads its queue from config', function () {
    config()->set('analysis.queue', 'analysis');
    config()->set('analysis.timeout', 1234);

    $job = new AnalyzeDocumentJob('document-uuid');

    expect($job->documentId)->toBe('document-uuid')
        ->and($job->queue)->toBe('analysis')
        ->and($job->timeout)->toBe(1234)
        ->and($job->tries)->toBe(1);
});

it('locks a single concurrent run per document with an outliving expiry', function () {
    config()->set('analysis.timeout', 900);
    config()->set('analysis.lock_expiry_buffer', 60);

    $middleware = (new AnalyzeDocumentJob('document-uuid'))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);

    $lock = $middleware[0];

    expect($lock->key)->toBe('document-analysis:document-uuid')
        ->and($lock->expiresAfter)->toBe(960)
        ->and($lock->releaseAfter)->toBeNull();
});

it('aborts quietly when the document was deleted while queued', function () {
    $this->mock(RunsDocumentAnalysis::class)->shouldNotReceive('run');

    (new AnalyzeDocumentJob(fake()->uuid()))->handle(app(RunsDocumentAnalysis::class));

    expect(true)->toBeTrue();
});

it('does not run the pipeline for a terminal document', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    $this->mock(RunsDocumentAnalysis::class)->shouldNotReceive('run');

    (new AnalyzeDocumentJob($document->getKey()))->handle(app(RunsDocumentAnalysis::class));

    expect($document->refresh()->status)->toBe(DocumentStatus::Completed);
});

it('runs the pipeline for a pending document', function () {
    $document = ResearchedDocument::factory()->pending()->create();

    $this->mock(RunsDocumentAnalysis::class)
        ->shouldReceive('run')
        ->once()
        ->withArgs(fn (ResearchedDocument $passed): bool => $passed->is($document));

    (new AnalyzeDocumentJob($document->getKey()))->handle(app(RunsDocumentAnalysis::class));
});

it('marks a processing document failed as a worker-level safety net', function () {
    $document = ResearchedDocument::factory()->processing()->create();

    (new AnalyzeDocumentJob($document->getKey()))->failed(new RuntimeException('worker timeout'));

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Analisis dokumen gagal. Silakan coba lagi.');
});

it('never overwrites a completed document on failure', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    (new AnalyzeDocumentJob($document->getKey()))->failed(new RuntimeException('late failure'));

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_error->toBeNull();
});
