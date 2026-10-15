<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DocumentTree;

uses(RefreshDatabase::class);

it('maps references in reading order and defaults a missing finding to pending', function () {
    $tree = DocumentTree::create();
    $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250, 'title' => 'Second']);
    $first = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150, 'title' => 'First']);
    ReferenceFinding::factory()->forReference($first)->valid()->create(['confidence' => 0.9]);

    $payload = app(ReportDataBuilder::class)->build($tree->document->refresh());

    expect(collect($payload->references)->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($payload->references[0]->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($payload->references[0]->confidence)->toBe(0.9)
        ->and($payload->references[1]->status)->toBe(ReferenceFindingStatus::Pending)
        ->and($payload->references[1]->confidence)->toBeNull()
        ->and($payload->references[1]->reason)->toBeNull();
});

it('includes only citation issues with their derived status, label and message', function () {
    $tree = DocumentTree::create();

    $invalid = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150, 'title' => 'Invalid entry']);
    ReferenceFinding::factory()->forReference($invalid)->invalid()->create();

    $valid = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    ReferenceFinding::factory()->forReference($valid)->valid()->create();

    $unresolvedReference = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 350]);

    $tree->citation($invalid, [
        'citation_text' => '(Invalid, 2020)',
        'text_start_offset' => 110,
        'text_end_offset' => 120,
    ]);
    $tree->citation($valid, [
        'citation_text' => '(Valid, 2020)',
        'text_start_offset' => 210,
        'text_end_offset' => 220,
    ]);
    $unresolved = $tree->citation($unresolvedReference, [
        'citation_text' => '(Unresolved, 2020)',
        'text_start_offset' => 310,
        'text_end_offset' => 320,
    ]);
    $unresolved->update(['resolution_state' => CitationResolutionState::Unresolved]);
    $tree->citation(null, [
        'citation_text' => '(Ghost, 2022)',
        'text_start_offset' => 410,
        'text_end_offset' => 420,
    ]);

    $payload = app(ReportDataBuilder::class)->build($tree->document->refresh());

    $issues = collect($payload->citationIssues);

    expect($issues->pluck('status')->all())->toBe([
        CitationStatus::Unreliable,
        CitationStatus::Unresolved,
        CitationStatus::Hallucination,
    ])
        ->and($issues->pluck('citationText')->all())->toBe([
            '(Invalid, 2020)',
            '(Unresolved, 2020)',
            '(Ghost, 2022)',
        ])
        ->and($issues[0]->message)->toBe((string) CitationStatus::Unreliable->message())
        ->and($issues[1]->message)->toBe((string) CitationStatus::Unresolved->message())
        ->and($issues[2]->message)->toBe((string) CitationStatus::Hallucination->message())
        ->and($issues[0]->referenceLabel)->toBe('Invalid entry')
        ->and($issues[1]->referenceLabel)->not->toBeNull()
        ->and($issues[2]->referenceLabel)->toBeNull();
});

it('truncates long text to the configured preview length', function () {
    config(['reports.text_preview_length' => 20]);

    $tree = DocumentTree::create();
    $tree->reference([
        'raw_text' => str_repeat('a', 100),
        'title' => str_repeat('b', 100),
        'authors' => str_repeat('c', 100),
        'text_start_offset' => 100,
        'text_end_offset' => 150,
    ]);
    $tree->citation(null, [
        'citation_text' => str_repeat('d', 100),
        'text_start_offset' => 110,
        'text_end_offset' => 120,
    ]);

    $payload = app(ReportDataBuilder::class)->build($tree->document->refresh());

    expect($payload->references[0]->rawText)->toBe(Str::limit(str_repeat('a', 100), 20))
        ->and($payload->references[0]->title)->toBe(Str::limit(str_repeat('b', 100), 20))
        ->and($payload->references[0]->authors)->toBe(Str::limit(str_repeat('c', 100), 20))
        ->and($payload->citationIssues[0]->citationText)->toBe(Str::limit(str_repeat('d', 100), 20))
        ->and($payload->references[0]->rawText)->not->toBe(str_repeat('a', 100));
});

it('includes zero-count summaries for a document without rows', function () {
    $tree = DocumentTree::create();

    $payload = app(ReportDataBuilder::class)->build($tree->document->refresh());

    expect($payload->references)->toBe([])
        ->and($payload->citationIssues)->toBe([])
        ->and($payload->documentName)->toBe($tree->document->name)
        ->and($payload->summary->totalReferences)->toBe(0)
        ->and($payload->summary->totalCitations)->toBe(0)
        ->and($payload->summary->invalid)->toBe(0)
        ->and($payload->summary->hallucinationCitations)->toBe(0);
});
