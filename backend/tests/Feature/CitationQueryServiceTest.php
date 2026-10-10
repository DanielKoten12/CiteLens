<?php

use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Models\ReferenceFinding;
use App\Services\Citation\CitationQueryService;
use Tests\Support\DocumentTree;

/**
 * A document with one citation for every derived status.
 *
 * @return array{
 *     tree: DocumentTree,
 *     citations: array<string, string>,
 *     reference: string
 * }
 */
function citationStatusFixture(): array
{
    $tree = DocumentTree::create();

    $reference = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);
    ReferenceFinding::factory()->forReference($reference)->valid()->create();

    $pendingReference = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    ReferenceFinding::factory()->forReference($pendingReference)->pending()->create();

    $invalidReference = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 350]);
    ReferenceFinding::factory()->forReference($invalidReference)->invalid()->create();

    $citations = [
        'hallucination' => $tree->citation(null, [
            'citation_text' => '(Halu, 2020)',
            'text_start_offset' => 400,
            'text_end_offset' => 410,
        ])->getKey(),
        'pending' => $tree->citation($pendingReference, [
            'citation_text' => '(Pending, 2020)',
            'text_start_offset' => 200,
            'text_end_offset' => 210,
        ])->getKey(),
        'valid' => $tree->citation($reference, [
            'citation_text' => '(Valid, 2020)',
            'text_start_offset' => 100,
            'text_end_offset' => 110,
        ])->getKey(),
        'unreliable' => $tree->citation($invalidReference, [
            'citation_text' => '(Invalid, 2020)',
            'text_start_offset' => 300,
            'text_end_offset' => 310,
        ])->getKey(),
        'unresolved' => $tree->citation(null, [
            'citation_text' => '(Kandidat, 2020)',
            'resolution_state' => CitationResolutionState::Unresolved,
            'text_start_offset' => 350,
            'text_end_offset' => 360,
        ])->getKey(),
    ];

    return ['tree' => $tree, 'citations' => $citations, 'reference' => $reference->getKey()];
}

it('filters citations by every derived status', function () {
    $fixture = citationStatusFixture();
    $service = app(CitationQueryService::class);

    foreach (CitationStatus::cases() as $status) {
        $ids = collect($service->paginate($fixture['tree']->document, $status)->items())
            ->pluck('id')->all();

        expect($ids)->toBe([$fixture['citations'][$status->value]]);
    }
});

it('filters citations by reference', function () {
    $fixture = citationStatusFixture();

    $ids = collect(app(CitationQueryService::class)
        ->paginate($fixture['tree']->document, referenceId: $fixture['reference'])
        ->items())
        ->pluck('id')->all();

    expect($ids)->toBe([$fixture['citations']['valid']]);
});

it('orders citations by document position with nulls last', function () {
    $fixture = citationStatusFixture();

    $ids = collect(app(CitationQueryService::class)->paginate($fixture['tree']->document)->items())
        ->pluck('id')->all();

    expect($ids)->toBe([
        $fixture['citations']['valid'],
        $fixture['citations']['pending'],
        $fixture['citations']['unreliable'],
        $fixture['citations']['unresolved'],
        $fixture['citations']['hallucination'],
    ]);
});
