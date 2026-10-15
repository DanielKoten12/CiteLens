<?php

use App\Enums\ReportStatus;
use App\Exceptions\ReportRenderingException;
use App\Jobs\GenerateDocumentReportJob;
use App\Models\File;
use App\Models\GeneratedDocumentReport;
use App\Services\Reports\Contracts\ReportRenderer;
use App\Services\Reports\ReportStateService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface;
use Tests\Support\DocumentTree;
use Tests\Support\FakeReportRenderer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'reports.disk' => 'reports',
        'reports.timeout' => 300,
        'reports.lock_expiry_buffer' => 60,
    ]);

    Storage::fake('local');
    Storage::fake('reports');
});

it('completes a report and stores its pdf', function () {
    $tree = DocumentTree::create();
    $tree->reference([
        'raw_text' => 'Smith, J. (2020). A paper.',
        'title' => null,
        'text_start_offset' => 100,
        'text_end_offset' => 150,
    ]);
    $report = $tree->report();

    $renderer = new FakeReportRenderer;
    app()->instance(ReportRenderer::class, $renderer);

    GenerateDocumentReportJob::dispatch($report->getKey());

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Completed)
        ->and($report->generated_at)->not->toBeNull()
        ->and($report->error)->toBeNull()
        ->and($report->file_id)->not->toBeNull()
        ->and($renderer->lastHtml)->toContain($tree->document->name)
        ->and($renderer->lastHtml)->toContain('Smith, J. (2020). A paper.');

    $file = File::query()->find($report->file_id);

    expect($file)->not->toBeNull()
        ->and($file->fileable_type)->toBe('generated_document_report')
        ->and($file->fileable_id)->toBe($report->getKey())
        ->and($file->disk)->toBe('reports')
        ->and($file->filename)->toBe("laporan-{$report->getKey()}.pdf")
        ->and($file->path)->toStartWith('reports/'.$report->getKey().'/');

    Storage::disk('reports')->assertExists($file->path);
});

it('stores the report on the configured report disk, not the default disk', function () {
    $tree = DocumentTree::create();
    $report = $tree->report();

    app()->instance(ReportRenderer::class, new FakeReportRenderer);

    GenerateDocumentReportJob::dispatch($report->getKey());

    $file = File::query()->find($report->fresh()->file_id);

    expect($file)->not->toBeNull()
        ->and($file->disk)->toBe('reports')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Storage::disk('reports')->assertExists($file->path);
});

it('runs the real renderer through the injected psr-18 client', function () {
    $tree = DocumentTree::create();
    $report = $tree->report();

    $handler = new MockHandler([new Response(200, [], '%PDF-1.4 real')]);
    $this->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($handler)]));

    GenerateDocumentReportJob::dispatch($report->getKey());

    expect($report->fresh()->status)->toBe(ReportStatus::Completed);

    $request = $handler->getLastRequest();

    expect($request)->not->toBeNull()
        ->and((string) $request?->getBody())->toContain('index.html');

    $file = File::query()->find($report->fresh()->file_id);

    expect($file)->not->toBeNull()
        ->and(Storage::disk('reports')->get($file->path))->toBe('%PDF-1.4 real');
});

it('records a safe failure when the renderer is unavailable', function () {
    $tree = DocumentTree::create();
    $report = $tree->report();

    app()->instance(ReportRenderer::class, new FakeReportRenderer(
        failure: ReportRenderingException::serviceUnavailable(),
    ));

    Log::spy();

    GenerateDocumentReportJob::dispatch($report->getKey());

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Failed)
        ->and($report->error)->toBe('Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.')
        ->and($report->file_id)->toBeNull()
        ->and($report->generated_at)->toBeNull()
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk('reports')->allFiles())->toBe([]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Document report generation failed.'
            && $context['report_id'] === $report->getKey()
            && $context['document_id'] === $report->researched_document_id);
});

it('records a safe failure when the report cannot be stored', function () {
    $tree = DocumentTree::create();
    $report = $tree->report();

    app()->instance(ReportRenderer::class, new FakeReportRenderer);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();

    Storage::shouldReceive('disk')->andReturn($disk);

    Log::spy();

    GenerateDocumentReportJob::dispatch($report->getKey());

    expect($report->fresh()->status)->toBe(ReportStatus::Failed)
        ->and($report->fresh()->error)->toBe('Laporan gagal disimpan. Silakan coba lagi.')
        ->and(File::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')->once();
});

it('does nothing when the report was deleted before the job ran', function () {
    $renderer = new FakeReportRenderer;
    app()->instance(ReportRenderer::class, $renderer);

    GenerateDocumentReportJob::dispatch((string) Str::uuid());

    expect($renderer->lastHtml)->toBe('');
});

it('does not regenerate a terminal report', function () {
    $tree = DocumentTree::create();
    $report = $tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);

    $renderer = new FakeReportRenderer;
    app()->instance(ReportRenderer::class, $renderer);

    GenerateDocumentReportJob::dispatch($report->getKey());

    expect($renderer->lastHtml)->toBe('')
        ->and($report->fresh()->status)->toBe(ReportStatus::Completed);
});

it('fails defensively when the document is not completed', function () {
    $tree = DocumentTree::create();
    $report = GeneratedDocumentReport::factory()->for($tree->document)->create();

    $renderer = new FakeReportRenderer;
    app()->instance(ReportRenderer::class, $renderer);

    GenerateDocumentReportJob::dispatch($report->getKey());

    expect($report->fresh()->status)->toBe(ReportStatus::Failed)
        ->and($report->fresh()->error)->toBe(ReportStateService::SAFE_FAILURE_MESSAGE)
        ->and($renderer->lastHtml)->toBe('');
});

it('cleans up the stored file when the report is deleted while rendering', function () {
    $tree = DocumentTree::create();
    $report = $tree->report();

    $renderer = new FakeReportRenderer(onRender: function () use ($report): void {
        $report->delete();
    });
    app()->instance(ReportRenderer::class, $renderer);

    GenerateDocumentReportJob::dispatch($report->getKey());

    expect(GeneratedDocumentReport::query()->whereKey($report->getKey())->exists())->toBeFalse()
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk('reports')->allFiles())->toBe([]);
});

it('marks a report failed from the failed hook and leaves terminal rows untouched', function () {
    $tree = DocumentTree::create();
    $pending = $tree->report();
    $completed = $tree->report(['status' => ReportStatus::Completed, 'generated_at' => now()]);

    (new GenerateDocumentReportJob($pending->getKey()))->failed(new RuntimeException('worker died'));
    (new GenerateDocumentReportJob($completed->getKey()))->failed(new RuntimeException('worker died'));

    expect($pending->fresh()->status)->toBe(ReportStatus::Failed)
        ->and($pending->fresh()->error)->toBe(ReportStateService::SAFE_FAILURE_MESSAGE)
        ->and($completed->fresh()->status)->toBe(ReportStatus::Completed);
});

it('locks per report for timeout plus buffer without releasing', function () {
    $job = new GenerateDocumentReportJob('report-id');
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('report-generation:report-id')
        ->and($middleware[0]->expiresAfter)->toBe(360)
        ->and($middleware[0]->releaseAfter)->toBeNull();
});
