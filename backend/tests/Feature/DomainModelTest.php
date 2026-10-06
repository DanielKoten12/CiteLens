<?php

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Enums\ReportStatus;
use App\Models\File;
use App\Models\GeneratedDocumentReport;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReference;
use App\Models\ResearchedDocumentReferenceLocation;
use App\Models\User;

it('builds and resolves the full document tree', function () {
    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->completed()->create();

    $reference = ResearchedDocumentReference::factory()
        ->for($document)
        ->withDoi()
        ->create();

    $referenceLocation = ResearchedDocumentReferenceLocation::factory()
        ->for($reference, 'reference')
        ->create();

    $citation = ResearchedDocumentCitation::factory()
        ->for($document)
        ->pairedTo($reference)
        ->create();

    $citationLocation = ResearchedDocumentCitationLocation::factory()
        ->for($citation, 'citation')
        ->create();

    $finding = ReferenceFinding::factory()
        ->forReference($reference)
        ->valid()
        ->create();

    $candidate = ReferenceFindingCandidate::factory()->for($finding)->create();
    $finding->update(['selected_candidate_id' => $candidate->getKey()]);

    $report = GeneratedDocumentReport::factory()->for($document)->completed()->create();

    expect($document->fresh()->references)->toHaveCount(1)
        ->and($document->fresh()->citations)->toHaveCount(1)
        ->and($document->fresh()->findings)->toHaveCount(1)
        ->and($document->fresh()->reports)->toHaveCount(1)
        ->and($reference->fresh()->locations)->toHaveCount(1)
        ->and($reference->fresh()->locations->first()->is($referenceLocation))->toBeTrue()
        ->and($reference->fresh()->finding->is($finding))->toBeTrue()
        ->and($citation->fresh()->reference->is($reference))->toBeTrue()
        ->and($citation->fresh()->locations)->toHaveCount(1)
        ->and($citation->fresh()->locations->first()->is($citationLocation))->toBeTrue()
        ->and($finding->fresh()->candidates)->toHaveCount(1)
        ->and($finding->fresh()->selectedCandidate->is($candidate))->toBeTrue()
        ->and($finding->fresh()->reference->is($reference))->toBeTrue()
        ->and($report->fresh()->file)->toBeNull();
});

it('casts document lifecycle attributes to enums', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    expect($document->status)->toBe(DocumentStatus::Completed)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Completed)
        ->and($document->fresh()->analysis_step->value)->toBe('completed');
});

it('casts finding and report statuses to enums', function () {
    $reference = ResearchedDocumentReference::factory()->create();
    $finding = ReferenceFinding::factory()->forReference($reference)->suspicious()->create();
    $report = GeneratedDocumentReport::factory()->failed()->create();

    expect($finding->fresh()->status)->toBe(ReferenceFindingStatus::Suspicious)
        ->and($finding->fresh()->is_manual)->toBeFalse()
        ->and($report->fresh()->status)->toBe(ReportStatus::Failed)
        ->and($report->fresh()->error)->not->toBeNull();
});

it('stores morph aliases for document files', function () {
    $document = ResearchedDocument::factory()->create();

    $file = $document->file()->create([
        'filename' => 'skripsi.pdf',
        'path' => 'documents/'.$document->id.'/skripsi.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);

    expect($file->fresh()->fileable_type)->toBe('researched_document')
        ->and($file->fresh()->fileable)->toBeInstanceOf(ResearchedDocument::class)
        ->and($file->fresh()->fileable->is($document))->toBeTrue();
});

it('stores morph aliases for report files and links the canonical file pointer', function () {
    $report = GeneratedDocumentReport::factory()->completed()->create();

    $file = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
        'filename' => 'laporan.pdf',
        'path' => 'reports/'.$report->id.'/laporan.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
    ]);

    $report->update(['file_id' => $file->getKey()]);

    expect($file->fresh()->fileable_type)->toBe('generated_document_report')
        ->and($file->fresh()->fileable)->toBeInstanceOf(GeneratedDocumentReport::class)
        ->and($report->fresh()->file->is($file))->toBeTrue();
});

it('scopes documents and child rows to the owning user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $document = ResearchedDocument::factory()->for($owner)->create();
    $reference = ResearchedDocumentReference::factory()->for($document)->create();
    $citation = ResearchedDocumentCitation::factory()->for($document)->pairedTo($reference)->create();
    $finding = ReferenceFinding::factory()->forReference($reference)->valid()->create();
    $report = GeneratedDocumentReport::factory()->for($document)->create();

    expect(ResearchedDocument::query()->forUser($owner)->count())->toBe(1)
        ->and(ResearchedDocument::query()->forUser($other)->count())->toBe(0)
        ->and(ResearchedDocumentReference::query()->forUser($owner)->count())->toBe(1)
        ->and(ResearchedDocumentReference::query()->forUser($other)->count())->toBe(0)
        ->and(ResearchedDocumentCitation::query()->forUser($owner)->count())->toBe(1)
        ->and(ResearchedDocumentCitation::query()->forUser($other)->count())->toBe(0)
        ->and(ReferenceFinding::query()->forUser($owner)->count())->toBe(1)
        ->and(ReferenceFinding::query()->forUser($other)->count())->toBe(0)
        ->and(GeneratedDocumentReport::query()->forUser($owner)->count())->toBe(1)
        ->and(GeneratedDocumentReport::query()->forUser($other)->count())->toBe(0)
        ->and($owner->researchedDocuments()->count())->toBe(1)
        ->and($other->researchedDocuments()->count())->toBe(0);

    expect($citation->researched_document_id)->toBe($document->id)
        ->and($finding->researched_document_id)->toBe($document->id)
        ->and($report->researched_document_id)->toBe($document->id);
});

it('scopes child rows to a specific document', function () {
    $document = ResearchedDocument::factory()->create();
    $otherDocument = ResearchedDocument::factory()->create();

    ResearchedDocumentReference::factory()->for($document)->count(2)->create();
    ResearchedDocumentReference::factory()->for($otherDocument)->create();

    expect(ResearchedDocumentReference::query()->forDocument($document)->count())->toBe(2)
        ->and(ResearchedDocumentReference::query()->forDocument($otherDocument)->count())->toBe(1);
});

it('keeps factory sequence indexes unique per parent', function () {
    $reference = ResearchedDocumentReference::factory()->create();
    $citation = ResearchedDocumentCitation::factory()->create();
    $finding = ReferenceFinding::factory()->forReference($reference)->create();

    ResearchedDocumentReferenceLocation::factory()->for($reference, 'reference')->count(3)->create();
    ResearchedDocumentCitationLocation::factory()->for($citation, 'citation')->count(3)->create();
    ReferenceFindingCandidate::factory()->for($finding)->count(3)->create();

    expect($reference->fresh()->locations->pluck('location_index')->all())->toBe([0, 1, 2])
        ->and($citation->fresh()->locations->pluck('location_index')->all())->toBe([0, 1, 2])
        ->and($finding->fresh()->candidates->pluck('rank')->all())->toBe([1, 2, 3]);
});
