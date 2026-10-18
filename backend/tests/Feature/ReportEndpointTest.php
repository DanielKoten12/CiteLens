<?php

use App\Enums\ReportStatus;
use App\Jobs\GenerateDocumentReportJob;
use App\Models\File;
use App\Models\GeneratedDocumentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentTree;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['reports.disk' => 'reports']);

    Storage::fake('local');
    Storage::fake('reports');

    $this->tree = DocumentTree::create();
    $this->tree->complete();

    $this->user = $this->tree->user;
    $this->document = $this->tree->document;
    $this->base = "/api/v1/documents/{$this->document->getKey()}/reports";
});

function attachReportFile(GeneratedDocumentReport $report, string $path = 'reports/x/report.pdf'): File
{
    Storage::disk('reports')->put($path, 'pdf');

    $file = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'path' => $path,
        'disk' => 'reports',
    ]);

    $report->update(['file_id' => $file->getKey()]);

    return $file;
}

it('queues a report for a completed document', function () {
    Bus::fake();

    $response = $this->actingAs($this->user)->postJson($this->base);

    $response->assertStatus(202)
        ->assertJsonStructure([
            'data' => ['id', 'document_id', 'status', 'download_url', 'generated_at'],
            'message',
        ])
        ->assertJsonPath('data.document_id', $this->document->getKey())
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.download_url', null)
        ->assertJsonPath('data.generated_at', null)
        ->assertJsonPath('message', 'Laporan sedang dibuat.');

    $report = GeneratedDocumentReport::query()->firstOrFail();

    expect($report->status)->toBe(ReportStatus::Pending)
        ->and($response->json('data.id'))->toBe($report->getKey());

    Bus::assertDispatched(
        GenerateDocumentReportJob::class,
        fn (GenerateDocumentReportJob $job): bool => $job->reportId === $report->getKey(),
    );
});

it('rejects a report for a non-completed document', function (string $status) {
    Bus::fake();

    $tree = DocumentTree::create();
    $tree->document->update(['status' => $status]);

    $this->actingAs($tree->user)
        ->postJson("/api/v1/documents/{$tree->document->getKey()}/reports")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', 'Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis.');

    expect(GeneratedDocumentReport::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
})->with(['pending', 'processing', 'failed']);

it('allows multiple reports per document', function () {
    Bus::fake();

    $this->actingAs($this->user)->postJson($this->base)->assertStatus(202);
    $this->actingAs($this->user)->postJson($this->base)->assertStatus(202);

    expect(GeneratedDocumentReport::query()->count())->toBe(2);
    Bus::assertDispatched(GenerateDocumentReportJob::class, 2);
});

it('lists a document reports newest first with their download state', function () {
    $old = $this->tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()->subDay()]);
    $old->forceFill(['created_at' => now()->subDay()])->save();

    $new = $this->tree->report();

    $response = $this->actingAs($this->user)->getJson($this->base);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'document_id', 'status', 'download_url', 'generated_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $new->getKey())
        ->assertJsonPath('data.1.id', $old->getKey())
        ->assertJsonPath('data.1.download_url', null);
});

it('resolves a download url for a completed report with a stored file', function () {
    $report = $this->tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);
    attachReportFile($report, 'reports/x/report.pdf');

    $this->actingAs($this->user)->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.0.download_url', fn ($url): bool => is_string($url) && str_contains($url, 'reports/x/report.pdf'));
});

it('rejects invalid pagination with 422', function (string $query) {
    $this->actingAs($this->user)->getJson($this->base.'?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'per_page zero' => 'per_page=0',
    'per_page too large' => 'per_page=101',
    'page zero' => 'page=0',
]);

it('shows a pending report with null error and download url', function () {
    $report = $this->tree->report();

    $this->actingAs($this->user)->getJson("/api/v1/reports/{$report->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $report->getKey())
        ->assertJsonPath('data.document_id', $this->document->getKey())
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.error', null)
        ->assertJsonPath('data.download_url', null)
        ->assertJsonPath('data.generated_at', null);
});

it('shows a failed report with its safe error', function () {
    $report = $this->tree->report([
        'status' => ReportStatus::Failed,
        'error' => 'Laporan gagal dibuat. Silakan coba lagi.',
    ]);

    $this->actingAs($this->user)->getJson("/api/v1/reports/{$report->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'Laporan gagal dibuat. Silakan coba lagi.')
        ->assertJsonPath('data.download_url', null)
        ->assertJsonPath('data.generated_at', null);
});

it('shows a completed report with a download url', function () {
    $report = $this->tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);
    attachReportFile($report);

    $this->actingAs($this->user)->getJson("/api/v1/reports/{$report->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.download_url', fn ($url): bool => is_string($url) && $url !== '')
        ->assertJsonPath('data.error', null);
});

it('returns 404 for an unknown report', function () {
    $id = (string) Str::uuid();

    $this->actingAs($this->user)->getJson("/api/v1/reports/{$id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Laporan tidak ditemukan.');

    $this->actingAs($this->user)->deleteJson("/api/v1/reports/{$id}")
        ->assertStatus(404)
        ->assertJsonPath('error.message', 'Laporan tidak ditemukan.');
});

it('deletes a completed report with its row, file row and stored object', function () {
    $report = $this->tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);
    $file = attachReportFile($report, 'reports/x/report.pdf');

    $response = $this->actingAs($this->user)->deleteJson("/api/v1/reports/{$report->getKey()}");

    $response->assertNoContent();

    expect(GeneratedDocumentReport::query()->whereKey($report->getKey())->exists())->toBeFalse()
        ->and(File::query()->whereKey($file->getKey())->exists())->toBeFalse();

    Storage::disk('reports')->assertMissing('reports/x/report.pdf');
});

it('removes a report object from the report disk without touching document files', function () {
    Storage::disk('local')->put('documents/x/doc.pdf', 'pdf');
    $documentFile = File::factory()->create([
        'fileable_type' => 'researched_document',
        'fileable_id' => $this->document->getKey(),
        'path' => 'documents/x/doc.pdf',
        'disk' => 'local',
    ]);

    $report = $this->tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);
    attachReportFile($report);

    $this->actingAs($this->user)->deleteJson("/api/v1/reports/{$report->getKey()}")
        ->assertNoContent();

    Storage::disk('reports')->assertMissing('reports/x/report.pdf');
    Storage::disk('local')->assertExists('documents/x/doc.pdf');
    expect(File::query()->whereKey($documentFile->getKey())->exists())->toBeTrue();
});

it('deletes a report without a file', function (string $state) {
    $attributes = $state === 'failed'
        ? ['status' => ReportStatus::Failed, 'error' => 'Laporan gagal dibuat. Silakan coba lagi.']
        : [];

    $report = $this->tree->report($attributes);

    $this->actingAs($this->user)->deleteJson("/api/v1/reports/{$report->getKey()}")
        ->assertNoContent();

    expect(GeneratedDocumentReport::query()->whereKey($report->getKey())->exists())->toBeFalse();
})->with(['pending', 'failed']);
