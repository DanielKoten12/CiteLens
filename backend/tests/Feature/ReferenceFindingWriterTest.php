<?php

use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Services\Crossref\CrossrefWorkData;
use App\Services\ReferenceFinding\ReferenceFindingWriter;
use App\Services\Scoring\ReferenceVerdict;
use App\Services\Scoring\ScoreBreakdown;
use App\Services\Scoring\ScoredCandidate;
use Tests\Support\DocumentTree;

function writerCandidate(int $rank, float $confidence, string $doi): ScoredCandidate
{
    return new ScoredCandidate(
        work: new CrossrefWorkData(
            doi: $doi,
            title: 'Title '.$rank,
            authors: ['A B'],
            containerTitle: 'Journal',
            publicationYear: 2020,
            url: 'https://doi.org/'.$doi,
        ),
        breakdown: new ScoreBreakdown(
            signals: ['title' => $confidence, 'authors' => null, 'journal' => null, 'year' => null],
            final: $confidence,
            doiMatch: false,
            semanticUsed: false,
            semanticDegraded: false,
        ),
        rank: $rank,
        matchReason: 'reason '.$rank,
    );
}

function writerVerdict(string $referenceId, ReferenceFindingStatus $status, ?float $confidence, ?int $selectedRank, array $candidates): ReferenceVerdict
{
    return new ReferenceVerdict($referenceId, $status, $confidence, 'verdict reason', $selectedRank, $candidates);
}

it('creates one finding with ranked candidates and a selected candidate', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();

    app(ReferenceFindingWriter::class)->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::Valid, 0.9, 1, [
            writerCandidate(1, 0.9, '10.1/a'),
            writerCandidate(2, 0.6, '10.1/b'),
        ]),
    ]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect($finding->status)->toBe(ReferenceFindingStatus::Valid)
        ->and($finding->confidence)->toBe(0.9)
        ->and($finding->reason)->toBe('verdict reason')
        ->and($finding->candidates()->pluck('rank')->all())->toBe([1, 2])
        ->and($finding->selected_candidate_id)->toBe($finding->candidates()->where('rank', 1)->value('id'))
        ->and($finding->candidates()->where('rank', 1)->value('doi'))->toBe('10.1/a');
});

it('updates the existing finding in place on a second run', function () {
    // T-SCORE-07
    $tree = DocumentTree::create();
    $reference = $tree->reference();

    $writer = app(ReferenceFindingWriter::class);

    $writer->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::Suspicious, 0.6, 1, [writerCandidate(1, 0.6, '10.1/a')]),
    ]);

    $writer->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::Valid, 0.95, 2, [
            writerCandidate(1, 0.94, '10.1/a'),
            writerCandidate(2, 0.95, '10.1/b'),
        ]),
    ]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect(ReferenceFinding::query()->count())->toBe(1)
        ->and(ReferenceFindingCandidate::query()->count())->toBe(2)
        ->and($finding->candidates()->pluck('rank')->all())->toBe([1, 2])
        ->and($finding->selected_candidate_id)->toBe($finding->candidates()->where('rank', 2)->value('id'));
});

it('replaces candidates safely when a selected candidate is already set', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();

    $writer = app(ReferenceFindingWriter::class);

    $writer->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::Valid, 0.9, 1, [writerCandidate(1, 0.9, '10.1/a')]),
    ]);

    $writer->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::NotFound, null, null, [writerCandidate(1, 0.3, '10.1/c')]),
    ]);

    $finding = ReferenceFinding::query()->where('researched_document_reference_id', $reference->getKey())->firstOrFail();

    expect($finding->selected_candidate_id)->toBeNull()
        ->and($finding->status)->toBe(ReferenceFindingStatus::NotFound)
        ->and($finding->candidates()->pluck('doi')->all())->toBe(['10.1/c']);
});

it('never touches the manual review audit fields', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $user = $tree->user;

    $finding = ReferenceFinding::factory()->forReference($reference)->create([
        'is_manual' => true,
        'reviewed_by' => $user->getKey(),
        'reviewed_at' => now(),
    ]);

    app(ReferenceFindingWriter::class)->persistBatch($tree->document, [
        writerVerdict($reference->getKey(), ReferenceFindingStatus::Valid, 0.9, 1, [writerCandidate(1, 0.9, '10.1/a')]),
    ]);

    $finding->refresh();

    expect($finding->is_manual)->toBeTrue()
        ->and($finding->reviewed_by)->toBe($user->getKey())
        ->and($finding->reviewed_at)->not->toBeNull();
});
