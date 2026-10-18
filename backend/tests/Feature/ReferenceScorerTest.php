<?php

use App\Services\Analysis\EmbeddingIndex;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\Scoring\ReferenceScorer;
use App\Services\Scoring\ScoringReference;

beforeEach(function () {
    config([
        'scoring.weights.title' => 1.0,
        'scoring.weights.authors' => 0.0,
        'scoring.weights.journal' => 0.0,
        'scoring.weights.year' => 0.0,
        'scoring.thresholds.valid' => 0.85,
        'scoring.thresholds.suspicious' => 0.50,
        'scoring.semantic.enabled' => false,
    ]);
});

function scoringReference(array $attributes = []): ScoringReference
{
    return new ScoringReference(
        id: $attributes['id'] ?? 'ref-1',
        title: $attributes['title'] ?? 'Deep learning',
        authors: $attributes['authors'] ?? null,
        publicationName: $attributes['publication_name'] ?? null,
        publicationYear: $attributes['publication_year'] ?? null,
        doi: $attributes['doi'] ?? null,
        doiValid: ($attributes['doi'] ?? null) !== null,
    );
}

it('ranks the higher-confidence candidate first', function () {
    // T-SCORE-04
    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(),
        [
            new CrossrefWorkData(doi: '10.1/partial', title: 'Deep neural learning'),
            new CrossrefWorkData(doi: '10.1/exact', title: 'Deep learning'),
        ],
        EmbeddingIndex::empty(),
    );

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->work->doi)->toBe('10.1/exact')
        ->and($ranked[0]->rank)->toBe(1)
        ->and($ranked[1]->rank)->toBe(2)
        ->and($ranked[0]->confidence())->toBeGreaterThan($ranked[1]->confidence());
});

it('returns an empty ranking for no candidates', function () {
    expect(app(ReferenceScorer::class)->score(scoringReference(), [], EmbeddingIndex::empty()))->toBe([]);
});

it('redistributes weight over the available signals', function () {
    config([
        'scoring.weights.title' => 0.75,
        'scoring.weights.authors' => 0.25,
    ]);

    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(['title' => 'Deep learning', 'authors' => null]),
        [new CrossrefWorkData(doi: '10.1/a', title: 'Deep learning')],
        EmbeddingIndex::empty(),
    );

    expect($ranked[0]->confidence())->toBe(1.0)
        ->and($ranked[0]->breakdown->signal('authors'))->toBeNull();
});

it('floors an agreeing doi match above the valid threshold', function () {
    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(['doi' => '10.1/same', 'title' => 'Deep learning']),
        [new CrossrefWorkData(doi: '10.1/same', title: 'Deep learning methods')],
        EmbeddingIndex::empty(),
    );

    expect($ranked[0]->breakdown->doiMatch)->toBeTrue()
        ->and($ranked[0]->breakdown->conflicts)->toBe([])
        ->and($ranked[0]->confidence())->toBeGreaterThan(0.85);
});

it('does not floor a conflicting doi match', function () {
    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(['doi' => '10.1/same', 'title' => 'Deep learning']),
        [new CrossrefWorkData(doi: '10.1/same', title: 'Completely unrelated work')],
        EmbeddingIndex::empty(),
    );

    expect($ranked[0]->breakdown->doiMatch)->toBeTrue()
        ->and($ranked[0]->breakdown->conflicts)->not->toBe([])
        ->and($ranked[0]->confidence())->toBeLessThan(0.85);
});

it('uses the semantic signal when embeddings are available', function () {
    config(['scoring.semantic.enabled' => true]);

    $reference = scoringReference(['id' => 'ref-1']);

    $embeddings = EmbeddingIndex::fromVectors([
        EmbeddingIndex::referenceKey('ref-1') => [1.0, 0.0],
        EmbeddingIndex::candidateKey('ref-1', 0) => [0.0, 1.0],
        EmbeddingIndex::candidateKey('ref-1', 1) => [1.0, 0.0],
    ]);

    $ranked = app(ReferenceScorer::class)->score(
        $reference,
        [
            new CrossrefWorkData(doi: '10.1/orthogonal', title: 'Deep learning'),
            new CrossrefWorkData(doi: '10.1/identical', title: 'Deep learning'),
        ],
        $embeddings,
    );

    expect($ranked[0]->work->doi)->toBe('10.1/identical')
        ->and($ranked[0]->breakdown->semanticUsed)->toBeTrue();
});

it('marks semantics as degraded when embeddings are unavailable', function () {
    config(['scoring.semantic.enabled' => true]);

    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(),
        [new CrossrefWorkData(doi: '10.1/a', title: 'Deep learning')],
        EmbeddingIndex::unavailable(),
    );

    expect($ranked[0]->breakdown->semanticUsed)->toBeFalse()
        ->and($ranked[0]->breakdown->semanticDegraded)->toBeTrue();
});

it('produces a deterministic match reason with component evidence', function () {
    $ranked = app(ReferenceScorer::class)->score(
        scoringReference(['title' => 'Deep learning']),
        [new CrossrefWorkData(doi: '10.1/a', title: 'Deep learning')],
        EmbeddingIndex::empty(),
    );

    expect($ranked[0]->matchReason)->toBe('kemiripan judul 1.00.');
});
