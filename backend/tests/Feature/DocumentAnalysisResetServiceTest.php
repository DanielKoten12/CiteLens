<?php

use App\Models\File;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReference;
use App\Models\ResearchedDocumentReferenceLocation;
use App\Services\Document\DocumentAnalysisResetService;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->service = app(DocumentAnalysisResetService::class);
});

it('deletes every derived row of a document and keeps the document and file', function () {
    $tree = DocumentTree::create();
    $file = File::factory()->for($tree->document, 'fileable')->create();

    $pairedReference = $tree->reference(locations: 2);
    $tree->reference(locations: 1);          // reference without a finding
    $tree->finding($pairedReference, candidates: 3);

    $tree->citation($pairedReference, locations: 1);
    $tree->citation(null, locations: 2);     // unpaired (hallucination) citation

    $this->service->reset($tree->document);

    expect(ResearchedDocumentCitationLocation::query()->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(0)
        ->and(ResearchedDocumentReferenceLocation::query()->count())->toBe(0)
        ->and(ReferenceFindingCandidate::query()->count())->toBe(0)
        ->and(ReferenceFinding::query()->count())->toBe(0)
        ->and(ResearchedDocumentReference::query()->count())->toBe(0)
        ->and(ResearchedDocument::query()->count())->toBe(1)
        ->and(File::query()->whereKey($file->getKey())->exists())->toBeTrue();
});

it('is idempotent when run twice', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference(locations: 1);
    $tree->finding($reference, candidates: 1);
    $tree->citation($reference, locations: 1);

    $this->service->reset($tree->document);
    $this->service->reset($tree->document);

    expect(ResearchedDocumentReference::query()->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(0)
        ->and(ReferenceFinding::query()->count())->toBe(0);
});

it('only resets the given document', function () {
    $first = DocumentTree::create();
    $second = DocumentTree::create();

    $secondReference = $second->reference(locations: 1);
    $second->citation($secondReference, locations: 1);

    $this->service->reset($first->document);

    expect(ResearchedDocumentReference::query()->count())->toBe(1)
        ->and(ResearchedDocumentCitation::query()->count())->toBe(1);
});
