<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Services\Citations\CitationStatusResolver;
use Illuminate\Support\Facades\Http;
use Tests\Support\AnalysisHarness;
use Tests\Support\CrossrefFake;
use Tests\Support\Fixtures;
use Tests\Support\InferenceFake;

it('produces the expected findings for the three product cases', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed)
        ->and(ReferenceFinding::query()->count())->toBe(3);

    $references = $tree->document->references()->orderBy('text_start_offset')->get();

    // Case: DOI resolves and agrees with the metadata.
    $validDoi = ReferenceFinding::query()->where('researched_document_reference_id', $references[0]->getKey())->firstOrFail();
    $validCandidate = ReferenceFindingCandidate::query()->whereKey($validDoi->selected_candidate_id)->firstOrFail();

    expect($validDoi->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($validCandidate->doi)->toBe('10.1038/nature14539');

    // Case: valid reference without a DOI → suggested DOI on the top candidate.
    $noDoi = ReferenceFinding::query()->where('researched_document_reference_id', $references[1]->getKey())->firstOrFail();
    $suggested = ReferenceFindingCandidate::query()->whereKey($noDoi->selected_candidate_id)->firstOrFail();

    expect($noDoi->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($suggested->doi)->toBe('10.1234/example')
        ->and($suggested->rank)->toBe(1);

    // Case: DOI is present but malformed.
    $malformed = ReferenceFinding::query()->where('researched_document_reference_id', $references[2]->getKey())->firstOrFail();

    expect($malformed->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($malformed->reason)->toBe('Format DOI tidak valid.');
});

it('resolves in-text citations to their bibliography references', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    $citations = $tree->document->citations()->get()->keyBy('citation_text');

    $leCun = $citations->get('(LeCun et al., 2015)');
    $koten = $citations->get('(Koten, 2023)');
    $unpaired = $citations->get('(Tanpa rujukan, 2022)');

    $leCunReference = $tree->document->references()->where('title', 'Deep learning')->firstOrFail();
    $kotenReference = $tree->document->references()->where('title', 'Sistem deteksi plagiarisme')->firstOrFail();

    expect($leCun->researched_document_reference_id)->toBe($leCunReference->getKey())
        ->and($koten->researched_document_reference_id)->toBe($kotenReference->getKey())
        ->and($unpaired->researched_document_reference_id)->toBeNull();

    // Derived status comes from pairing + the reference verdict.
    $resolver = app(CitationStatusResolver::class);

    expect($resolver->resolve(CitationResolutionState::Paired, ReferenceFinding::query()
        ->where('researched_document_reference_id', $leCunReference->getKey())
        ->firstOrFail()->status))->toBe(CitationStatus::Valid)
        ->and($resolver->resolve(CitationResolutionState::Unmatched, null))->toBe(CitationStatus::Hallucination);
});

it('keeps candidate ranks unique and ordered for every finding', function () {
    InferenceFake::extraction();
    InferenceFake::embeddingsFromText();
    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    foreach (ReferenceFinding::query()->with('candidates')->get() as $finding) {
        $ranks = $finding->candidates->pluck('rank')->all();
        $expected = $ranks === [] ? [] : range(1, count($ranks));

        expect($ranks)->toBe($expected)
            ->and(array_unique($ranks))->toHaveCount(count($ranks));
    }
});

it('completes and records the semantic degradation when embeddings are unavailable', function () {
    $base = rtrim((string) config('services.inference.base_url'), '/');

    Http::fake([
        $base.'/v1/extract' => Http::response(Fixtures::json('inference/extract')),
        $base.'/v1/embeddings' => Http::response(['error' => ['code' => 'INFERENCE_FAILED']], 503),
    ]);

    CrossrefFake::forExtractFixture();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh()->status)->toBe(DocumentStatus::Completed);

    $reason = ReferenceFindingCandidate::query()->whereNotNull('match_reason')->value('match_reason');

    expect($reason)->toContain('kemiripan semantik tidak tersedia');
});

it('fails the document safely on a total crossref outage', function () {
    // F-XREF-02
    InferenceFake::extraction();
    CrossrefFake::unavailable();

    $tree = AnalysisHarness::document();

    AnalyzeDocumentJob::dispatchSync($tree->document->getKey());

    expect($tree->document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Validasi Crossref tidak tersedia. Coba lagi nanti.');
});
