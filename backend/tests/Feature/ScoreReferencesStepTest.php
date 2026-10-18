<?php

use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocumentReference;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\EmbeddingIndex;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Analysis\Steps\ScoreReferencesStep;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\Crossref\DoiLookup;
use App\Services\Crossref\DoiNormalizer;
use App\Services\Scoring\ScoringReference;
use Tests\Support\DocumentTree;

function scoreVerification(
    ResearchedDocumentReference $reference,
    DoiLookup $lookup,
    array $candidates,
    bool $transient = false,
): ReferenceVerification {
    return new ReferenceVerification(
        ScoringReference::fromModel($reference, app(DoiNormalizer::class)),
        $lookup,
        $candidates,
        $transient,
    );
}

function runScoring(DocumentTree $tree, array $verifications): void
{
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch($verifications));
    $context->setEmbeddings(EmbeddingIndex::empty());

    app(ScoreReferencesStep::class)->handle($tree->document, $context);
}

it('marks a doi that resolves to a different publication as invalid', function () {
    // T-REF-07
    $tree = DocumentTree::create();

    $reference = $tree->reference([
        'doi' => '10.1038/nature14539',
        'title' => 'Deep learning',
        'authors' => 'LeCun, Y.',
        'publication_year' => 2015,
    ]);

    runScoring($tree, [
        scoreVerification($reference, DoiLookup::Resolved, [
            new CrossrefWorkData(
                doi: '10.1038/nature14539',
                title: 'Attention is all you need',
                authors: ['Ashish Vaswani'],
                containerTitle: 'NeurIPS',
                publicationYear: 2017,
            ),
        ]),
    ]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect($finding->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($finding->reason)->toContain('DOI menunjuk ke publikasi yang berbeda')
        ->and($finding->reason)->toContain('judul');
});

it('marks a doi that does not resolve as invalid', function () {
    // F-XREF-01
    $tree = DocumentTree::create();
    $reference = $tree->reference(['doi' => '10.1234/missing', 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);

    runScoring($tree, [scoreVerification($reference, DoiLookup::NotFound, [])]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect($finding->status)->toBe(ReferenceFindingStatus::Invalid)
        ->and($finding->reason)->toBe('DOI tidak ditemukan di Crossref.');
});

it('marks a valid reference without a doi as valid and suggests the candidate doi', function () {
    // T-REF-08 / FR-R7
    $tree = DocumentTree::create();

    $reference = $tree->reference([
        'doi' => null,
        'title' => 'Sistem deteksi plagiarisme',
        'authors' => 'Koten, D. B.',
        'publication_name' => 'Jurnal Teknologi Informasi',
        'publication_year' => 2023,
    ]);

    runScoring($tree, [
        scoreVerification($reference, DoiLookup::NotPresent, [
            new CrossrefWorkData(
                doi: '10.1234/example',
                title: 'Sistem Deteksi Plagiarisme Dokumen',
                authors: ['Daniel Koten'],
                containerTitle: 'Jurnal Teknologi Informasi',
                publicationYear: 2023,
                url: 'https://doi.org/10.1234/example',
            ),
        ]),
    ]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();
    $selected = ReferenceFindingCandidate::query()->whereKey($finding->selected_candidate_id)->firstOrFail();

    expect($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($finding->selected_candidate_id)->not->toBeNull()
        ->and($selected->doi)->toBe('10.1234/example')
        ->and($selected->rank)->toBe(1);
});

it('keeps candidate ranks unique when scoring runs twice', function () {
    // T-SCORE-07
    $tree = DocumentTree::create();
    $reference = $tree->reference(['doi' => null, 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);

    $candidates = [
        new CrossrefWorkData(doi: '10.1/a', title: 'Deep learning'),
        new CrossrefWorkData(doi: '10.1/b', title: 'Deep learning methods'),
    ];

    runScoring($tree, [scoreVerification($reference, DoiLookup::NotPresent, $candidates)]);
    runScoring($tree, [scoreVerification($reference, DoiLookup::NotPresent, $candidates)]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect(ReferenceFinding::query()->count())->toBe(1)
        ->and($finding->candidates()->count())->toBe(2)
        ->and($finding->candidates()->pluck('rank')->all())->toBe([1, 2]);
});

it('marks a transient crossref failure as pending', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference(['doi' => '10.1/down', 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);

    runScoring($tree, [scoreVerification($reference, DoiLookup::NotPresent, [], transient: true)]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect($finding->status)->toBe(ReferenceFindingStatus::Pending)
        ->and($finding->confidence)->toBeNull()
        ->and($finding->reason)->toBe('Validasi Crossref gagal sementara. Coba lagi nanti.');
});

it('builds deterministic per-candidate match reasons', function () {
    // F-SCORE-01
    $tree = DocumentTree::create();
    $reference = $tree->reference(['doi' => null, 'title' => 'Deep learning', 'authors' => 'LeCun, Y.']);

    $candidates = [new CrossrefWorkData(doi: '10.1/a', title: 'Deep learning', publicationYear: 2015)];

    runScoring($tree, [scoreVerification($reference, DoiLookup::NotPresent, $candidates)]);
    $first = ReferenceFindingCandidate::query()->value('match_reason');

    runScoring($tree, [scoreVerification($reference, DoiLookup::NotPresent, $candidates)]);
    $second = ReferenceFindingCandidate::query()->value('match_reason');

    expect($first)->toBe($second)
        ->and($first)->toContain('kemiripan judul');
});
