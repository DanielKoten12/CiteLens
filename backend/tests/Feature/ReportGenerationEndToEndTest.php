<?php

use App\Enums\ReportStatus;
use App\Models\File;
use App\Models\GeneratedDocumentReport;
use App\Models\ReferenceFinding;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Client\ClientInterface;
use Tests\Support\DocumentTree;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['reports.disk' => 'reports']);

    Storage::fake('reports');
    Storage::fake('local');
});

/**
 * Bind the real Gotenberg renderer to a Guzzle mock client, so the sync-queue
 * end-to-end path exercises the real builder, Blade template and renderer.
 */
function bindGotenberg(MockHandler $handler): void
{
    app()->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($handler)]));
}

it('generates, exposes and deletes a report end to end', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference([
        'raw_text' => 'Koten, D. (2024). Pemeriksaan referensi.',
        'title' => null,
        'text_start_offset' => 100,
        'text_end_offset' => 150,
    ]);
    ReferenceFinding::factory()->forReference($reference)->valid()->create();
    $tree->citation($reference, ['citation_text' => '(Koten, 2024)', 'text_start_offset' => 110]);
    $tree->complete();

    $handler = new MockHandler([new Response(200, [], '%PDF-1.4 ok')]);
    bindGotenberg($handler);

    $base = "/api/v1/documents/{$tree->document->getKey()}/reports";

    // The 202 body reflects the row as created (pending), even though the sync
    // test queue runs the job inline before the response is serialized.
    $response = $this->actingAs($tree->user)->postJson($base);

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('message', 'Laporan sedang dibuat.');

    $id = $response->json('data.id');
    $report = GeneratedDocumentReport::query()->findOrFail($id);

    expect($report->status)->toBe(ReportStatus::Completed)
        ->and($report->file_id)->not->toBeNull()
        ->and($report->generated_at)->not->toBeNull()
        ->and($report->error)->toBeNull();

    $file = File::query()->findOrFail($report->file_id);

    expect($file->disk)->toBe('reports')
        ->and($file->fileable_type)->toBe('generated_document_report');

    Storage::disk('reports')->assertExists($file->path);

    expect((string) $handler->getLastRequest()?->getBody())
        ->toContain('index.html')
        ->toContain($tree->document->name)
        ->toContain('Koten, D. (2024). Pemeriksaan referensi.');

    $this->actingAs($tree->user)->getJson("/api/v1/reports/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.error', null)
        ->assertJsonPath('data.download_url', fn ($url): bool => is_string($url) && $url !== '');

    $this->actingAs($tree->user)->getJson($base)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $id);

    $this->actingAs($tree->user)->deleteJson("/api/v1/reports/{$id}")
        ->assertNoContent();

    expect(GeneratedDocumentReport::query()->whereKey($id)->exists())->toBeFalse()
        ->and(File::query()->whereKey($file->getKey())->exists())->toBeFalse();

    Storage::disk('reports')->assertMissing($file->path);
});

it('records a safe failure and regenerates a report', function () {
    $tree = DocumentTree::create();
    $tree->complete();

    $base = "/api/v1/documents/{$tree->document->getKey()}/reports";

    bindGotenberg(new MockHandler([new Response(500, [], 'boom')]));

    $first = $this->actingAs($tree->user)->postJson($base);
    $first->assertStatus(202);

    $failed = GeneratedDocumentReport::query()->findOrFail($first->json('data.id'));

    expect($failed->status)->toBe(ReportStatus::Failed)
        ->and($failed->error)->toBe('Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.')
        ->and($failed->file_id)->toBeNull()
        ->and($failed->generated_at)->toBeNull();

    // Regenerating is another POST; a healthy renderer completes the new row.
    bindGotenberg(new MockHandler([new Response(200, [], '%PDF-1.4 ok')]));

    $second = $this->actingAs($tree->user)->postJson($base);
    $second->assertStatus(202);

    $regenerated = GeneratedDocumentReport::query()->findOrFail($second->json('data.id'));

    expect($regenerated->status)->toBe(ReportStatus::Completed)
        ->and($regenerated->getKey())->not->toBe($failed->getKey())
        ->and(GeneratedDocumentReport::query()->count())->toBe(2);
});

it('cleans report files when the document is deleted', function () {
    $tree = DocumentTree::create();
    $tree->complete();

    bindGotenberg(new MockHandler([new Response(200, [], '%PDF-1.4 ok')]));

    $response = $this->actingAs($tree->user)
        ->postJson("/api/v1/documents/{$tree->document->getKey()}/reports");

    $report = GeneratedDocumentReport::query()->findOrFail($response->json('data.id'));
    $file = File::query()->findOrFail($report->file_id);

    Storage::disk('reports')->assertExists($file->path);

    $this->actingAs($tree->user)
        ->deleteJson("/api/v1/documents/{$tree->document->getKey()}")
        ->assertNoContent();

    expect(GeneratedDocumentReport::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);

    Storage::disk('reports')->assertMissing($file->path);
});
