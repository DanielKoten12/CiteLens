<?php

use App\Enums\DocumentStatus;
use App\Enums\MetadataSource;
use App\Enums\ReferenceFindingStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AnalysisHarness;
use Tests\Support\CrossrefFake;
use Tests\Support\GarudaFake;
use Tests\Support\InferenceFake;

beforeEach(function (): void {
    Cache::flush();

    config([
        'services.garuda.enabled' => true,
        'services.garuda.base_url' => 'https://garuda.kemdiktisaintek.go.id',
        'services.garuda.rows' => 3,
        'services.garuda.retries' => 0,
        'services.garuda.retry_backoff_ms' => 0,
        'services.garuda.min_interval_ms' => 0,
        'services.garuda.cache_ttl' => 3600,
        'services.garuda.negative_cache_ttl' => 60,
        'services.garuda.max_requests_per_document' => 500,
        'services.garuda.max_response_bytes' => 2097152,
        'services.garuda.user_agent' => null,
        'services.crossref.mailto' => 'test@example.com',
        'scoring.fallback.title_threshold' => 0.85,
    ]);
});

it('resolves a garuda-only reference and completes the document', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::results();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();
    $candidate = ReferenceFindingCandidate::query()->whereKey($finding->selected_candidate_id)->firstOrFail();

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_error->toBeNull()
        ->and($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($candidate->source)->toBe(MetadataSource::Garuda)
        ->and($candidate->doi)->toBeNull()
        ->and($candidate->url)->toBe('https://garuda.kemdiktisaintek.go.id/documents/detail/6584846');
});

it('fails the document when garuda is down and no primary candidate exists', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::unavailable();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Validasi Garuda tidak tersedia. Coba lagi nanti.');
});

it('completes with not_found when garuda has no matching record', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::NotFound)
        ->and($finding->reason)->toBe('Tidak ada kandidat ditemukan.');
});
