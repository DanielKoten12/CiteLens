<?php

use App\Services\Files\PrivateFileUrlResolver;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->resolver = app(PrivateFileUrlResolver::class);
});

it('returns a url containing the path for a faked disk', function () {
    Storage::fake('local');
    Storage::disk('local')->put('documents/x/report.pdf', 'pdf');

    $url = $this->resolver->resolve('documents/x/report.pdf');

    expect($url)->toBeString()->toContain('documents/x/report.pdf');
});

it('resolves against the explicitly passed disk', function () {
    Storage::fake('local');
    Storage::fake('reports');
    Storage::disk('reports')->put('reports/x/report.pdf', 'pdf');

    $url = $this->resolver->resolve('reports/x/report.pdf', 'reports');

    expect($url)->toBeString()->toContain('reports/x/report.pdf');
});

it('returns null for a null or empty path', function (?string $path) {
    expect($this->resolver->resolve($path))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
]);

it('returns null when the disk cannot build a url', function () {
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('unknown disk'));

    expect($this->resolver->resolve('documents/x/report.pdf'))->toBeNull();
});
