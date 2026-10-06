<?php

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReferenceLocation;
use Tests\Support\DocumentTree;

it('builds consecutive reference offsets and citation occurrence indexes', function () {
    $tree = DocumentTree::create();

    $first = $tree->reference();
    $second = $tree->reference();

    $firstCitation = $tree->citation();
    $secondCitation = $tree->citation(reference: $first);

    expect([$first->text_start_offset, $first->text_end_offset])->toBe([0, 120])
        ->and([$second->text_start_offset, $second->text_end_offset])->toBe([120, 240])
        ->and($firstCitation->occurrence_index)->toBe(0)
        ->and($secondCitation->occurrence_index)->toBe(1)
        ->and($firstCitation->researched_document_reference_id)->toBeNull()
        ->and($secondCitation->researched_document_reference_id)->toBe($first->getKey());
});

it('adds highlight locations with unique indexes', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference(locations: 2);
    $citation = $tree->citation(reference: $reference, locations: 3);

    expect($reference->locations)->toHaveCount(2)
        ->and($reference->locations->pluck('location_index')->all())->toBe([0, 1])
        ->and($citation->locations)->toHaveCount(3)
        ->and($citation->locations->pluck('location_index')->all())->toBe([0, 1, 2])
        ->and(ResearchedDocumentReferenceLocation::query()->count())->toBe(2)
        ->and(ResearchedDocumentCitationLocation::query()->count())->toBe(3);
});

it('creates findings with ranked candidates and selects the best one', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();

    $finding = $tree->finding($reference, attributes: ['status' => ReferenceFindingStatus::Suspicious, 'confidence' => 0.63], candidates: 2);

    expect($finding->candidates)->toHaveCount(2)
        ->and($finding->candidates->pluck('rank')->all())->toBe([1, 2])
        ->and($finding->selected_candidate_id)->toBe($finding->candidates->first()->getKey());
});

it('completes the document before adding a report', function () {
    $tree = DocumentTree::create();

    expect($tree->document->status)->toBe(DocumentStatus::Pending);

    $report = $tree->report();

    expect($tree->document->fresh()->status)->toBe(DocumentStatus::Completed)
        ->and($tree->document->fresh()->analysis_progress)->toBe(100)
        ->and($report->researched_document_id)->toBe($tree->document->getKey());
});
