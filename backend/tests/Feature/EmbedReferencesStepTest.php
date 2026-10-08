<?php

use App\Exceptions\InferenceClientException;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\EmbeddingIndex;
use App\Services\Analysis\ReferenceVerification;
use App\Services\Analysis\ReferenceVerificationBatch;
use App\Services\Analysis\Steps\EmbedReferencesStep;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\Crossref\DoiLookup;
use App\Services\Scoring\ScoringReference;
use Illuminate\Support\Facades\Http;
use Tests\Support\DocumentTree;
use Tests\Support\InferenceFake;

function embeddingVerification(string $id, ?string $title, array $candidateTitles = []): ReferenceVerification
{
    $candidates = array_map(
        static fn (string $title): CrossrefWorkData => new CrossrefWorkData(doi: null, title: $title),
        $candidateTitles,
    );

    return new ReferenceVerification(
        new ScoringReference($id, $title, null, null, null, null, false),
        DoiLookup::NotPresent,
        $candidates,
    );
}

it('embeds reference and candidate titles into the index', function () {
    InferenceFake::embeddingsFromText();

    $document = DocumentTree::create()->document;
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch([
        embeddingVerification('ref-1', 'Deep learning', ['Deep learning', 'Other work']),
    ]));

    app(EmbedReferencesStep::class)->handle($document, $context);

    $index = $context->embeddings();

    expect($index->isAvailable())->toBeTrue()
        ->and($index->vectorFor(EmbeddingIndex::referenceKey('ref-1')))->not->toBeNull()
        ->and($index->vectorFor(EmbeddingIndex::candidateKey('ref-1', 0)))->not->toBeNull()
        ->and($index->vectorFor(EmbeddingIndex::candidateKey('ref-1', 1)))->not->toBeNull();
});

it('degrades to string signals when the inference service is unavailable', function () {
    // T-SCORE-03
    InferenceFake::unavailable();

    $document = DocumentTree::create()->document;
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch([
        embeddingVerification('ref-1', 'Deep learning', ['Deep learning']),
    ]));

    app(EmbedReferencesStep::class)->handle($document, $context);

    expect($context->embeddings()->isAvailable())->toBeFalse()
        ->and($context->embeddings()->vectorFor(EmbeddingIndex::referenceKey('ref-1')))->toBeNull();
});

it('treats an embeddings 4xx as fatal', function () {
    Http::fake(['*/v1/embeddings' => Http::response('', 422)]);

    $document = DocumentTree::create()->document;
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch([
        embeddingVerification('ref-1', 'Deep learning'),
    ]));

    expect(fn () => app(EmbedReferencesStep::class)->handle($document, $context))
        ->toThrow(InferenceClientException::class);
});

it('does not call the service when semantics are disabled', function () {
    config(['scoring.semantic.enabled' => false]);
    Http::fake();

    $document = DocumentTree::create()->document;
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch([
        embeddingVerification('ref-1', 'Deep learning'),
    ]));

    app(EmbedReferencesStep::class)->handle($document, $context);

    expect($context->embeddings()->isAvailable())->toBeFalse();
    Http::assertNothingSent();
});

it('returns an empty index without http when there is nothing to embed', function () {
    Http::fake();

    $document = DocumentTree::create()->document;
    $context = new AnalysisContext;
    $context->setVerification(new ReferenceVerificationBatch([
        embeddingVerification('ref-1', null, []),
    ]));

    app(EmbedReferencesStep::class)->handle($document, $context);

    $index = $context->embeddings();

    expect($index->isAvailable())->toBeTrue()
        ->and($index->hasVectors())->toBeFalse();
    Http::assertNothingSent();
});
