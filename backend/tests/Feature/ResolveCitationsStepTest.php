<?php

use App\Data\Inference\ExtractionResultData;
use App\Models\ResearchedDocumentCitation;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Steps\PersistExtractionStep;
use App\Services\Analysis\Steps\ResolveCitationsStep;
use Tests\Support\DocumentTree;

function runResolutionStep(DocumentTree $tree): void
{
    app(ResolveCitationsStep::class)->handle($tree->document, new AnalysisContext);
}

it('pairs an apa citation with the matching reference', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference([
        'authors' => 'Koten, D. B.',
        'publication_year' => 2023,
        'text_start_offset' => 100,
        'text_end_offset' => 200,
    ]);
    $citation = $tree->citation(null, [
        'citation_text' => '(Koten, 2023)',
        'citation_marker' => 'Koten, 2023',
    ]);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBe($reference->getKey())
        ->and($tree->document->refresh()->analysis_progress)->toBe(95);
});

it('falls back to the citation text when the marker is null', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference(['authors' => 'Koten, D.', 'publication_year' => 2023]);
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)', 'citation_marker' => null]);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBe($reference->getKey());
});

it('leaves a citation with a year mismatch unpaired', function () {
    $tree = DocumentTree::create();

    $tree->reference(['authors' => 'Koten, D.', 'publication_year' => 1999, 'text_start_offset' => 100]);
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBeNull();
});

it('resolves an ieee ordinal against the bibliography order', function () {
    $tree = DocumentTree::create();

    $first = $tree->reference(['authors' => 'LeCun, Y.', 'text_start_offset' => 100, 'text_end_offset' => 150]);
    $second = $tree->reference(['authors' => 'Koten, D.', 'text_start_offset' => 200, 'text_end_offset' => 250]);
    $citation = $tree->citation(null, ['citation_text' => '[2]', 'citation_marker' => '[2]']);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBe($second->getKey())
        ->and($second->getKey())->not->toBe($first->getKey());
});

it('orders offset-less references last for ieee ordinals', function () {
    $tree = DocumentTree::create();

    $withoutOffset = $tree->reference([
        'authors' => 'Tanpa Offset',
        'text_start_offset' => null,
        'text_end_offset' => null,
    ]);
    $withOffset = $tree->reference([
        'authors' => 'Dengan Offset',
        'text_start_offset' => 10,
        'text_end_offset' => 20,
    ]);
    $citation = $tree->citation(null, ['citation_text' => '[1]', 'citation_marker' => '[1]']);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBe($withOffset->getKey())
        ->and($withOffset->getKey())->not->toBe($withoutOffset->getKey());
});

it('is idempotent when run twice', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference(['authors' => 'Koten, D.', 'publication_year' => 2023]);
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    runResolutionStep($tree);
    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBe($reference->getKey())
        ->and(ResearchedDocumentCitation::query()->whereNotNull('researched_document_reference_id')->count())->toBe(1);
});

it('never pairs a citation with a reference of another document', function () {
    $tree = DocumentTree::create();
    $other = DocumentTree::create();

    $other->reference(['authors' => 'Koten, D.', 'publication_year' => 2023]);
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBeNull();
});

it('leaves every citation unpaired when the document has no references', function () {
    $tree = DocumentTree::create();

    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    runResolutionStep($tree);

    expect($citation->refresh()->researched_document_reference_id)->toBeNull();
});

/**
 * Persist an extraction payload and resolve it with the same context, so the
 * transient extraction hints are available.
 *
 * @param  array<string, mixed>  $payload
 */
function persistAndResolve(DocumentTree $tree, array $payload): AnalysisContext
{
    $context = new AnalysisContext;
    $context->setExtraction(ExtractionResultData::from($payload));

    app(PersistExtractionStep::class)->handle($tree->document, $context);
    app(ResolveCitationsStep::class)->handle($tree->document, $context);

    return $context;
}

it('trusts the extraction hint when the marker is unparseable', function () {
    $tree = DocumentTree::create();

    persistAndResolve($tree, [
        'references' => [
            ['raw_text' => 'First', 'authors' => 'First, A.', 'publication_year' => 2001],
            ['raw_text' => 'Second', 'authors' => 'Second, B.', 'publication_year' => 2002],
        ],
        'citations' => [
            ['citation_text' => 'lihat lampiran', 'citation_marker' => null, 'reference_index' => 1, 'occurrence_index' => 0],
        ],
    ]);

    $citation = $tree->document->citations()->firstOrFail();
    $second = $tree->document->references()->where('raw_text', 'Second')->firstOrFail();

    expect($citation->researched_document_reference_id)->toBe($second->getKey());
});

it('resolves ieee ordinals by payload order when offsets are missing', function () {
    $tree = DocumentTree::create();

    persistAndResolve($tree, [
        'references' => [
            ['raw_text' => 'First', 'authors' => 'First, A.', 'publication_year' => 2001],
            ['raw_text' => 'Second', 'authors' => 'Second, B.', 'publication_year' => 2002],
        ],
        'citations' => [
            ['citation_text' => '[2]', 'citation_marker' => '[2]', 'occurrence_index' => 0],
        ],
    ]);

    $citation = $tree->document->citations()->firstOrFail();
    $second = $tree->document->references()->where('raw_text', 'Second')->firstOrFail();

    expect($citation->researched_document_reference_id)->toBe($second->getKey());
});
