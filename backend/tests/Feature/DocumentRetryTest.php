<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\File;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Tests\Support\DocumentTree;

it('re-queues a failed document, clears derived rows and dispatches the job', function () {
    Bus::fake();

    $user = User::factory()->create();
    $tree = DocumentTree::create($user, [
        'status' => DocumentStatus::Failed,
        'analysis_progress' => 35,
        'analysis_step' => AnalysisStep::CrossrefValidation,
        'analysis_error' => 'Analisis dokumen gagal.',
    ]);

    $reference = $tree->reference(locations: 1);
    $tree->finding($reference, candidates: 1);
    $tree->citation($reference, locations: 1);
    $file = File::factory()->for($tree->document, 'fileable')->create();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/documents/{$tree->document->getKey()}/retry");

    $response->assertStatus(202)
        ->assertJsonPath('data.id', $tree->document->getKey())
        ->assertJsonPath('data.status', DocumentStatus::Pending->value)
        ->assertJsonPath('data.progress', 0)
        ->assertJsonPath('data.current_step', AnalysisStep::Queued->value)
        ->assertJsonPath('message', 'Analisis dijadwalkan ulang.');

    Bus::assertDispatched(
        AnalyzeDocumentJob::class,
        fn (AnalyzeDocumentJob $job): bool => $job->documentId === $tree->document->getKey(),
    );

    expect(ResearchedDocumentReference::query()->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(0)
        ->and(ReferenceFinding::query()->count())->toBe(0)
        ->and(File::query()->whereKey($file->getKey())->exists())->toBeTrue();

    $tree->document->refresh();

    expect($tree->document)
        ->status->toBe(DocumentStatus::Pending)
        ->analysis_error->toBeNull()
        ->analysis_started_at->toBeNull()
        ->analysis_completed_at->toBeNull();
});

it('refuses retry from a non-failed document with 409', function (string $state) {
    Bus::fake();

    $user = User::factory()->create();

    $document = match ($state) {
        'processing' => ResearchedDocument::factory()->for($user)->processing()->create(),
        'completed' => ResearchedDocument::factory()->for($user)->completed()->create(),
        default => ResearchedDocument::factory()->for($user)->pending()->create(),
    };

    $this->actingAs($user)
        ->postJson("/api/v1/documents/{$document->getKey()}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', 'Analisis hanya dapat diulang untuk dokumen yang gagal.');

    Bus::assertNothingDispatched();
    expect($document->refresh()->status)->toBe($state === 'processing'
        ? DocumentStatus::Processing
        : DocumentStatus::from($state));
})->with(['pending', 'processing', 'completed']);

it('cannot retry twice in a row', function () {
    Bus::fake();

    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->failed()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/documents/{$document->getKey()}/retry")
        ->assertStatus(202);

    $this->actingAs($user)
        ->postJson("/api/v1/documents/{$document->getKey()}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    Bus::assertDispatchedTimes(AnalyzeDocumentJob::class, 1);
});
