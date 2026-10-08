<?php

use App\Models\File;
use App\Models\GeneratedDocumentReport;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentTree;

it('deletes one document, cascades and removes files and objects', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $tree = DocumentTree::create($user);

    $reference = $tree->reference(locations: 1);
    $tree->finding($reference, candidates: 1);
    $tree->citation($reference, locations: 1);

    $documentPath = 'documents/'.$tree->document->getKey().'/laporan.pdf';
    Storage::disk('local')->put($documentPath, 'pdf');
    File::factory()->create([
        'fileable_type' => 'researched_document',
        'fileable_id' => $tree->document->getKey(),
        'path' => $documentPath,
    ]);

    $report = $tree->report();
    $reportPath = 'reports/'.$report->getKey().'.pdf';
    Storage::disk('local')->put($reportPath, 'pdf');
    $reportFile = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'path' => $reportPath,
    ]);
    $report->update(['file_id' => $reportFile->getKey()]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/documents/{$tree->document->getKey()}")
        ->assertNoContent();

    expect(ResearchedDocument::query()->count())->toBe(0)
        ->and(ResearchedDocumentReference::query()->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(0)
        ->and(ReferenceFinding::query()->count())->toBe(0)
        ->and(GeneratedDocumentReport::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);

    Storage::disk('local')->assertMissing($documentPath);
    Storage::disk('local')->assertMissing($reportPath);
});

it('purges only the authenticated user history', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $other = User::factory()->create();

    $mine = collect(range(1, 3))->map(fn () => DocumentTree::create($user)->document);
    $theirs = DocumentTree::create($other)->document;

    $this->actingAs($user)->deleteJson('/api/v1/documents')->assertNoContent();

    expect(ResearchedDocument::query()->whereKey($theirs->getKey())->exists())->toBeTrue()
        ->and(ResearchedDocument::query()->whereIn('id', $mine->pluck('id'))->count())->toBe(0);
});

it('returns 204 when purging an empty history', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/documents')->assertNoContent();
});

it('logs but does not fail when a stored object cannot be deleted', function () {
    $user = User::factory()->create();
    $tree = DocumentTree::create($user);

    File::factory()->create([
        'fileable_type' => 'researched_document',
        'fileable_id' => $tree->document->getKey(),
        'path' => 'documents/missing.pdf',
    ]);

    Storage::shouldReceive('disk')->andThrow(new RuntimeException('disk unavailable'));
    Log::shouldReceive('warning')->once();

    $this->actingAs($user)
        ->deleteJson("/api/v1/documents/{$tree->document->getKey()}")
        ->assertNoContent();

    expect(ResearchedDocument::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});
