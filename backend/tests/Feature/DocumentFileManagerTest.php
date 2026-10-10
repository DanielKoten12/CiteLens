<?php

use App\Models\File;
use App\Services\Document\DocumentDeletionService;
use App\Services\Document\DocumentFileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocumentTree;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('reports');

    $this->files = app(DocumentFileManager::class);
});

it('stores generated pdf bytes on the given disk', function () {
    $report = DocumentTree::create()->report();

    $file = $this->files->storePdf($report, '%PDF-1.4 bytes', 'laporan.pdf', 'reports');

    expect($file->disk)->toBe('reports')
        ->and($file->path)->toStartWith('reports/'.$report->getKey().'/')
        ->and($file->path)->toEndWith('.pdf')
        ->and($file->filename)->toBe('laporan.pdf')
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->size)->toBe(strlen('%PDF-1.4 bytes'));

    Storage::disk('reports')->assertExists($file->path);
    Storage::disk('local')->assertMissing($file->path);
});

it('deletes the object from the disk recorded on its row', function () {
    Storage::disk('reports')->put('reports/x/y.pdf', 'pdf');
    $file = File::factory()->create(['path' => 'reports/x/y.pdf', 'disk' => 'reports']);

    $this->files->delete($file);

    Storage::disk('reports')->assertMissing('reports/x/y.pdf');
    expect(File::query()->whereKey($file->getKey())->exists())->toBeFalse();
});

it('detaches every row of a report and returns its objects', function () {
    $report = DocumentTree::create()->report();
    $morph = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'path' => 'reports/'.$report->getKey().'/morph.pdf',
        'disk' => 'reports',
    ]);
    $canonical = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'path' => 'reports/'.$report->getKey().'/canonical.pdf',
        'disk' => 'reports',
    ]);
    $report->update(['file_id' => $canonical->getKey()]);

    $objects = $this->files->detachForReport($report);

    expect($objects)->toHaveCount(2)
        ->and(collect($objects)->pluck('disk')->unique()->all())->toBe(['reports'])
        ->and(collect($objects)->pluck('path')->sort()->values()->all())->toBe([
            'reports/'.$report->getKey().'/canonical.pdf',
            'reports/'.$report->getKey().'/morph.pdf',
        ])
        ->and(File::query()->whereIn('id', [$morph->getKey(), $canonical->getKey()])->count())->toBe(0)
        ->and($report->fresh()->file_id)->toBeNull();
});

it('cleans each file from its own disk when a document is deleted', function () {
    $tree = DocumentTree::create();
    $document = $tree->document;

    Storage::disk('local')->put('documents/x/doc.pdf', 'pdf');
    File::factory()->create([
        'fileable_type' => 'researched_document',
        'fileable_id' => $document->getKey(),
        'path' => 'documents/x/doc.pdf',
        'disk' => 'local',
    ]);

    $report = $tree->report();

    Storage::disk('reports')->put('reports/x/rep.pdf', 'pdf');
    $reportFile = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'path' => 'reports/x/rep.pdf',
        'disk' => 'reports',
    ]);
    $report->update(['file_id' => $reportFile->getKey()]);

    app(DocumentDeletionService::class)->delete($document);

    Storage::disk('local')->assertMissing('documents/x/doc.pdf');
    Storage::disk('reports')->assertMissing('reports/x/rep.pdf');
    expect(File::query()->count())->toBe(0);
});
