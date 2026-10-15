<?php

use App\Data\Inference\ExtractionResultData;
use App\Exceptions\ExtractionFailedException;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Steps\PersistExtractionStep;
use Illuminate\Support\Facades\DB;
use Tests\Support\DocumentTree;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->step = app(PersistExtractionStep::class);
});

function extractionFixture(): ExtractionResultData
{
    return ExtractionResultData::from(Fixtures::json('inference/extract')['data']);
}

function contextWith(ExtractionResultData $extraction): AnalysisContext
{
    $context = new AnalysisContext;
    $context->setExtraction($extraction);

    return $context;
}

it('persists references, citations and locations with normalized fields', function () {
    $tree = DocumentTree::create();

    $this->step->handle($tree->document, contextWith(extractionFixture()));

    expect(DB::table('researched_document_references')->count())->toBe(3)
        ->and(DB::table('researched_document_reference_locations')->count())->toBe(3)
        ->and(DB::table('researched_document_citations')->count())->toBe(3)
        ->and(DB::table('researched_document_citation_locations')->count())->toBe(3);

    $references = DB::table('researched_document_references')
        ->orderBy('text_start_offset')
        ->get();

    expect($references[0]->doi)->toBe('10.1038/nature14539')
        ->and($references[0]->publication_year)->toBe(2015)
        ->and($references[0]->created_at)->not->toBeNull()
        ->and($references[0]->updated_at)->not->toBeNull()
        ->and($references[1]->doi)->toBeNull()
        ->and($references[2]->doi)->toBe('not-a-doi');

    $locations = DB::table('researched_document_reference_locations')
        ->where('researched_document_reference_id', $references[0]->id)
        ->orderBy('location_index')
        ->get();

    expect($locations)->toHaveCount(2)
        ->and($locations[0]->location_index)->toBe(0)
        ->and($locations[0]->page_number)->toBe(12)
        ->and($locations[1]->location_index)->toBe(1)
        ->and($locations[1]->page_number)->toBe(13)
        ->and($locations[0]->coordinate_system)->toBe('pdf_points_top_left')
        ->and((float) $locations[0]->width)->toBe(451.2);
});

it('reindexes duplicate citation occurrences by document position', function () {
    $tree = DocumentTree::create();

    $this->step->handle($tree->document, contextWith(extractionFixture()));

    $citations = DB::table('researched_document_citations')
        ->pluck('occurrence_index', 'citation_text');

    expect($citations->all())->toBe([
        '(LeCun et al., 2015)' => 0,
        '(Koten, 2023)' => 1,
        '(Tanpa rujukan, 2022)' => 2,
    ]);

    $pairedTo = DB::table('researched_document_citations')
        ->pluck('researched_document_reference_id')
        ->unique()
        ->all();

    expect($pairedTo)->toBe([null]);
});

it('keeps extraction and running the step twice idempotent', function () {
    $tree = DocumentTree::create();

    $this->step->handle($tree->document, contextWith(extractionFixture()));
    $this->step->handle($tree->document, contextWith(extractionFixture()));

    expect(DB::table('researched_document_references')->count())->toBe(3)
        ->and(DB::table('researched_document_reference_locations')->count())->toBe(3)
        ->and(DB::table('researched_document_citations')->count())->toBe(3)
        ->and(DB::table('researched_document_citation_locations')->count())->toBe(3);
});

it('rejects a page number below one and leaves no rows behind', function () {
    $tree = DocumentTree::create();
    $payload = Fixtures::json('inference/extract')['data'];
    $payload['references'][0]['locations'][0]['page_number'] = 0;

    expect(fn () => $this->step->handle($tree->document, contextWith(ExtractionResultData::from($payload))))
        ->toThrow(ExtractionFailedException::class);

    expect(DB::table('researched_document_references')->count())->toBe(0)
        ->and(DB::table('researched_document_reference_locations')->count())->toBe(0)
        ->and(DB::table('researched_document_citations')->count())->toBe(0);
});

it('rejects an empty citation text', function () {
    $tree = DocumentTree::create();
    $payload = Fixtures::json('inference/extract')['data'];
    $payload['citations'][0]['citation_text'] = '   ';

    expect(fn () => $this->step->handle($tree->document, contextWith(ExtractionResultData::from($payload))))
        ->toThrow(ExtractionFailedException::class);

    expect(DB::table('researched_document_references')->count())->toBe(0)
        ->and(DB::table('researched_document_citations')->count())->toBe(0);
});

it('rejects over-long extracted text instead of truncating it', function () {
    config()->set('analysis.extraction.max_text_length', 10);

    $tree = DocumentTree::create();

    expect(fn () => $this->step->handle($tree->document, contextWith(extractionFixture())))
        ->toThrow(ExtractionFailedException::class);

    expect(DB::table('researched_document_references')->count())->toBe(0);
});

it('drops an implausible publication year', function () {
    $tree = DocumentTree::create();
    $payload = Fixtures::json('inference/extract')['data'];
    $payload['references'][0]['publication_year'] = 900;

    $this->step->handle($tree->document, contextWith(ExtractionResultData::from($payload)));

    $reference = DB::table('researched_document_references')
        ->where('raw_text', 'like', 'LeCun%')
        ->first();

    expect($reference->publication_year)->toBeNull();
});

it('clears the extraction from the context after persisting', function () {
    $tree = DocumentTree::create();
    $context = contextWith(extractionFixture());

    $this->step->handle($tree->document, $context);

    expect($context->hasExtraction())->toBeFalse()
        ->and(fn () => $context->takeExtraction())->toThrow(LogicException::class);
});

it('captures the extraction reference index hint on the context', function () {
    $tree = DocumentTree::create();
    $context = contextWith(extractionFixture());

    $this->step->handle($tree->document, $context);

    $references = $tree->document->references()->orderByRaw('text_start_offset IS NULL')->orderBy('text_start_offset')->get();
    $citations = $tree->document->citations()->get()->keyBy('citation_text');
    $hints = $context->extractionHints();

    expect($context->hasExtractionHints())->toBeTrue()
        ->and($hints->referenceIdForIndex(0))->toBe($references[0]->getKey())
        ->and($hints->referenceIdForIndex(1))->toBe($references[1]->getKey())
        ->and($hints->referenceIdForIndex(99))->toBeNull()
        ->and($hints->hintForCitation($citations['(LeCun et al., 2015)']->getKey()))->toBe(0)
        ->and($hints->hintForCitation($citations['(Koten, 2023)']->getKey()))->toBe(1)
        ->and($hints->hintForCitation($citations['(Tanpa rujukan, 2022)']->getKey()))->toBeNull();
});
