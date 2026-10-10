<?php

use Illuminate\Support\Facades\File;

it('evaluates a dataset from the command line', function () {
    $this->artisan('citations:evaluate', [
        'dataset' => base_path('tests/Fixtures/citations/evaluation/seed.json'),
    ])
        ->expectsOutputToContain('pair_f1')
        ->assertSuccessful();
});

it('writes a json report when requested', function () {
    $path = storage_path('framework/testing/citation-evaluation.json');

    File::delete($path);

    $this->artisan('citations:evaluate', [
        'dataset' => base_path('tests/Fixtures/citations/evaluation/seed.json'),
        '--json' => $path,
    ])->assertSuccessful();

    expect(File::exists($path))->toBeTrue();

    $report = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

    expect((float) $report['pair_precision'])->toBe(1.0)
        ->and($report['total_citations'])->toBe(9);

    File::delete($path);
});

it('fails on a missing dataset', function () {
    $this->artisan('citations:evaluate', ['dataset' => '/does/not/exist.json'])
        ->expectsOutputToContain('Dataset not found')
        ->assertFailed();
});

it('fails on a malformed dataset', function () {
    $path = storage_path('framework/testing/citation-evaluation-malformed.json');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '{not json');

    $this->artisan('citations:evaluate', ['dataset' => $path])
        ->expectsOutputToContain('not valid JSON')
        ->assertFailed();

    File::delete($path);
});
