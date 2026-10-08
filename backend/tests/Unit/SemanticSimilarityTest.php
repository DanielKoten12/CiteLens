<?php

use App\Services\Scoring\SemanticSimilarity;

beforeEach(function () {
    $this->semantic = new SemanticSimilarity;
});

it('computes cosine similarity for known vectors', function () {
    expect($this->semantic->cosine([1.0, 0.0], [1.0, 0.0]))->toEqualWithDelta(1.0, 0.0001)
        ->and($this->semantic->cosine([1.0, 0.0], [0.0, 1.0]))->toEqualWithDelta(0.0, 0.0001)
        ->and($this->semantic->cosine([1.0, 0.0], [-1.0, 0.0]))->toEqualWithDelta(-1.0, 0.0001);
});

it('returns null for missing, empty, zero or mismatched vectors', function () {
    expect($this->semantic->cosine(null, [1.0]))->toBeNull()
        ->and($this->semantic->cosine([1.0], null))->toBeNull()
        ->and($this->semantic->cosine([], [1.0]))->toBeNull()
        ->and($this->semantic->cosine([0.0, 0.0], [1.0, 0.0]))->toBeNull()
        ->and($this->semantic->cosine([1.0, 0.0], [1.0]))->toBeNull()
        ->and($this->semantic->cosine([1.0, 'x'], [1.0, 1.0]))->toBeNull();
});
