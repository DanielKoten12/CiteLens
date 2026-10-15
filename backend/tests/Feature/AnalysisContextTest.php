<?php

use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\CitationExtractionHints;
use App\Services\Analysis\EmbeddingIndex;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Crossref\DoiLookup;
use App\Services\Scoring\ScoringReference;

function contextReference(): ScoringReference
{
    return new ScoringReference(
        id: 'ref-1',
        title: 'Deep learning',
        authors: 'LeCun, Y.',
        publicationName: 'Nature',
        publicationYear: 2015,
        doi: '10.1038/nature14539',
        doiValid: true,
    );
}

it('exposes verification and embedding artifacts with typed accessors', function () {
    $context = new AnalysisContext;

    $batch = new ReferenceVerificationBatch([
        new ReferenceVerification(contextReference(), DoiLookup::Resolved, []),
    ]);

    $context->setVerification($batch);
    $context->setEmbeddings(EmbeddingIndex::fromVectors(['r:ref-1' => [1.0, 0.0]]));

    expect($context->hasVerification())->toBeTrue()
        ->and($context->verification())->toBe($batch)
        ->and($context->hasEmbeddings())->toBeTrue()
        ->and($context->embeddings()->vectorFor('r:ref-1'))->toBe([1.0, 0.0]);
});

it('throws when an artifact has not been set by an earlier step', function () {
    $context = new AnalysisContext;

    expect(fn () => $context->verification())->toThrow(LogicException::class)
        ->and(fn () => $context->embeddings())->toThrow(LogicException::class);
});

it('consumes the verification batch and clears it', function () {
    $context = new AnalysisContext;
    $batch = new ReferenceVerificationBatch([]);

    $context->setVerification($batch);

    expect($context->takeVerification())->toBe($batch)
        ->and($context->hasVerification())->toBeFalse()
        ->and(fn () => $context->verification())->toThrow(LogicException::class);
});

it('exposes extraction hints with typed accessors', function () {
    $context = new AnalysisContext;
    $hints = new CitationExtractionHints([0 => 'ref-1'], ['cit-1' => 0]);

    $context->setExtractionHints($hints);

    expect($context->hasExtractionHints())->toBeTrue()
        ->and($context->extractionHints())->toBe($hints)
        ->and($context->extractionHints()->referenceIdForIndex(0))->toBe('ref-1')
        ->and($context->extractionHints()->hintForCitation('cit-1'))->toBe(0);
});

it('throws when extraction hints have not been set', function () {
    expect(fn () => (new AnalysisContext)->extractionHints())->toThrow(LogicException::class);
});
