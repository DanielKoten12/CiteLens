<?php

use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;
use App\Models\CitationResolutionCandidate;
use App\Services\Citation\CitationResolutionWriter;
use App\Services\Citations\CitationCandidate;
use App\Services\Citations\CitationResolution;
use Tests\Support\DocumentTree;

it('persists resolution state, provenance and candidates', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    app(CitationResolutionWriter::class)->persistBatch($tree->document, [
        $citation->getKey() => CitationResolution::paired(
            referenceId: $reference->getKey(),
            confidence: 0.95,
            method: CitationResolutionMethod::Apa,
            candidates: [
                new CitationCandidate($reference->getKey(), 0.95, CitationResolutionMethod::Apa, 'Kemiripan nama 0.95.', 1),
            ],
        ),
    ], [$citation->getKey() => 3]);

    $citation->refresh();

    expect($citation->researched_document_reference_id)->toBe($reference->getKey())
        ->and($citation->resolution_state)->toBe(CitationResolutionState::Paired)
        ->and($citation->resolution_method)->toBe(CitationResolutionMethod::Apa)
        ->and($citation->resolution_confidence)->toBe(0.95)
        ->and($citation->extraction_reference_index)->toBe(3);

    $candidate = CitationResolutionCandidate::query()->firstOrFail();

    expect($candidate->rank)->toBe(1)
        ->and($candidate->method)->toBe(CitationResolutionMethod::Apa)
        ->and($candidate->confidence)->toBe(0.95)
        ->and($candidate->researched_document_reference_id)->toBe($reference->getKey())
        ->and($candidate->reference->title)->toBe($reference->title);
});

it('persists an unresolved proposal without a pairing', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $citation = $tree->citation(null, ['citation_text' => '(Hartini)']);

    app(CitationResolutionWriter::class)->persistBatch($tree->document, [
        $citation->getKey() => new CitationResolution(
            state: CitationResolutionState::Unresolved,
            confidence: 0.88,
            method: CitationResolutionMethod::Apa,
            candidates: [
                new CitationCandidate($reference->getKey(), 0.88, CitationResolutionMethod::Apa, 'Kemiripan nama 0.88.', 1),
            ],
        ),
    ]);

    $citation->refresh();

    expect($citation->researched_document_reference_id)->toBeNull()
        ->and($citation->resolution_state)->toBe(CitationResolutionState::Unresolved)
        ->and($citation->resolution_confidence)->toBe(0.88)
        ->and(CitationResolutionCandidate::query()->count())->toBe(1);
});

it('resets previous state and candidates on a re-run', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();
    $citation = $tree->citation($reference, ['citation_text' => '(Koten, 2023)']);
    $other = $tree->citation(null, ['citation_text' => '(Andi, 2022)']);

    CitationResolutionCandidate::factory()->for($citation, 'citation')->for($reference, 'reference')->create();

    $writer = app(CitationResolutionWriter::class);

    $writer->persistBatch($tree->document, [
        $other->getKey() => CitationResolution::unmatched(),
    ]);

    expect($citation->refresh()->researched_document_reference_id)->toBeNull()
        ->and($citation->resolution_state)->toBe(CitationResolutionState::Unmatched)
        ->and(CitationResolutionCandidate::query()->count())->toBe(0)
        ->and($other->refresh()->resolution_state)->toBe(CitationResolutionState::Unmatched);
});

it('caps candidates at the configured maximum', function () {
    config(['scoring.citation_matching.max_candidates' => 2]);

    $tree = DocumentTree::create();
    $citation = $tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $candidates = [];

    foreach (range(1, 3) as $rank) {
        $reference = $tree->reference(['text_start_offset' => $rank * 100, 'text_end_offset' => ($rank * 100) + 50]);
        $candidates[] = new CitationCandidate($reference->getKey(), 0.9 - ($rank / 100), CitationResolutionMethod::Apa, null, $rank);
    }

    app(CitationResolutionWriter::class)->persistBatch($tree->document, [
        $citation->getKey() => new CitationResolution(
            state: CitationResolutionState::Unresolved,
            confidence: 0.89,
            method: CitationResolutionMethod::Apa,
            candidates: $candidates,
        ),
    ]);

    expect(CitationResolutionCandidate::query()->count())->toBe(2)
        ->and(CitationResolutionCandidate::query()->orderBy('rank')->pluck('rank')->all())->toBe([1, 2]);
});
