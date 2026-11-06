<?php

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ReferenceFinding;
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

it('confirms a doi that crossref misses with an openalex record', function () {
    InferenceFake::extractionFrom('inference/extract-doi-fallback');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::empty();
    OpenAlexFake::install(doiFixtures: ['10.1234/not-in-crossref' => 'openalex/doi-found']);

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($finding->reason)->toBe('DOI cocok dengan metadata OpenAlex.');
});

it('keeps a conflicting publication invalid when a fallback resolver confirms the doi', function () {
    InferenceFake::extractionFrom('inference/extract-doi-fallback');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::install(doiFixtures: ['10.1234/not-in-crossref' => 'semantic_scholar/doi-conflict']);
    OpenAlexFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($finding->reason)->toContain('DOI menunjuk ke publikasi yang berbeda.');
});

it('never reports a doi as invalid when every resolver could not answer', function () {
    InferenceFake::extractionFrom('inference/extract-doi-fallback');
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
        ->and($finding->reason)->toBe(MatchReasonBuilder::DOI_UNKNOWN);
});

it('reports a generic doi miss when every enabled resolver answered 404', function () {
    InferenceFake::extractionFrom('inference/extract-doi-fallback');
    InferenceFake::embeddingsFromText();
    CrossrefFake::install();
    GarudaFake::empty();
    SemanticScholarFake::empty();
    OpenAlexFake::empty();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $finding = ReferenceFinding::query()->firstOrFail();

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and($finding->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($finding->reason)->toBe(MatchReasonBuilder::DOI_NOT_FOUND);
});
