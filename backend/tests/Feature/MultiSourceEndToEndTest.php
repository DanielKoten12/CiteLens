<?php

use App\Enums\DocumentStatus;
use App\Enums\MetadataSource;
use App\Enums\ReferenceFindingStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Services\Scoring\MatchReasonBuilder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AnalysisHarness;
use Tests\Support\CrossrefFake;
use Tests\Support\GarudaFake;
use Tests\Support\InferenceFake;
use Tests\Support\MetadataSourceConfig;
use Tests\Support\OpenAlexFake;
use Tests\Support\SemanticScholarFake;

beforeEach(function (): void {
    Cache::flush();
    MetadataSourceConfig::apply();
});

it('resolves a reference found only in semantic scholar and completes the document', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::results();
    OpenAlexFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();
    $candidate = ReferenceFindingCandidate::query()->whereKey($finding->selected_candidate_id)->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($candidate->source)->toBe(MetadataSource::SemanticScholar)
        ->and($candidate->doi)->toBe('10.1234/s2-jesasi-2026');
});

it('resolves a reference found only in openalex and completes the document', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::empty();
    OpenAlexFake::results();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();
    $candidate = ReferenceFindingCandidate::query()->whereKey($finding->selected_candidate_id)->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($candidate->source)->toBe(MetadataSource::OpenAlex)
        ->and($candidate->doi)->toBe('10.1234/openalex-jesasi-2026');
});

it('continues to the next source when a degradable source is down', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::unavailable(503);
    OpenAlexFake::results();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();
    $candidate = ReferenceFindingCandidate::query()->whereKey($finding->selected_candidate_id)->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($candidate->source)->toBe(MetadataSource::OpenAlex);
});

it('marks a reference pending instead of not_found when every enabled source failed', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::unavailable(503);
    OpenAlexFake::unavailable(503);

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Pending)
        ->and($finding->reason)->toBe(app(MatchReasonBuilder::class)->transient(MetadataSource::SemanticScholar));
});

it('reports not_found only when every enabled source answered and none matched', function () {
    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::empty();
    OpenAlexFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::NotFound)
        ->and($finding->reason)->toBe(MatchReasonBuilder::NOT_FOUND);
});

it('fails the document safely when an optional source is configured fatal', function () {
    MetadataSourceConfig::apply([
        'analysis.metadata.failure_policies.semantic_scholar' => 'fatal',
    ]);

    InferenceFake::extractionFrom('inference/extract-garuda');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::unavailable(503);
    OpenAlexFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Validasi Semantic Scholar tidak tersedia. Coba lagi nanti.');
});
