<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnalysisHarness;
use Tests\Support\CrossrefFake;
use Tests\Support\InferenceFake;

it('drives a document to completed through the queued job', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_progress->toBe(100)
        ->analysis_step->toBe(AnalysisStep::Completed)
        ->analysis_error->toBeNull();

    expect(ResearchedDocumentReference::query()->count())->toBe(3)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(3)
        ->and(ReferenceFinding::query()->count())->toBe(3);
});

it('never runs the pipeline synchronously during upload', function () {
    Storage::fake('local');
    Bus::fake();

    // T-PIPE-05: the upload request queues the analysis; the pipeline is never
    // resolved inside the request, even when the queue is faked.
    $this->mock(RunsDocumentAnalysis::class)->shouldNotReceive('run');

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_step', 'queued');

    $document = $user->researchedDocuments()->firstOrFail();

    Bus::assertDispatched(
        AnalyzeDocumentJob::class,
        fn (AnalyzeDocumentJob $job): bool => $job->documentId === $document->getKey(),
    );

    expect($document->analysis_progress)->toBe(0);
});

it('exposes the worker-level failure safety net for a crashed run', function () {
    $document = AnalysisHarness::document()->document;
    $document->update(['status' => DocumentStatus::Processing, 'analysis_progress' => 40]);

    (new AnalyzeDocumentJob($document->getKey()))->failed(new RuntimeException('worker timeout'));

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Analisis dokumen gagal. Silakan coba lagi.')
        ->analysis_progress->toBe(40);
});
