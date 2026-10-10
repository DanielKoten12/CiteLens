<?php

use App\Data\Citation\CitationDetailData;
use App\Data\Citation\CitationSummaryData;
use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;
use App\Enums\CitationStatus;
use App\Models\CitationResolutionCandidate;
use App\Models\ReferenceFinding;
use App\Services\Citations\CitationStatusResolver;
use Tests\Support\DocumentTree;

it('serializes a citation summary with its derived status and reference preview', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference([
        'title' => 'Sistem deteksi plagiarisme',
        'text_start_offset' => 100,
        'text_end_offset' => 150,
    ]);
    ReferenceFinding::factory()->forReference($reference)->valid()->create();

    $citation = $tree->citation($reference, [
        'citation_text' => '(Koten, 2023)',
        'citation_marker' => 'Koten, 2023',
        'context_before' => 'Sebelumnya, ',
        'context_after' => ' melaporkan.',
        'text_start_offset' => 50,
        'text_end_offset' => 60,
        'occurrence_index' => 7,
        'resolution_method' => CitationResolutionMethod::Apa,
    ]);

    $citation->load('reference.finding');

    $status = app(CitationStatusResolver::class)->resolve(
        CitationResolutionState::Paired,
        $citation->reference->finding->status,
    );
    $data = CitationSummaryData::forCitation($citation, $status)->toArray();

    expect($data['id'])->toBe($citation->getKey())
        ->and($data['citation_text'])->toBe('(Koten, 2023)')
        ->and($data['citation_marker'])->toBe('Koten, 2023')
        ->and($data['context_before'])->toBe('Sebelumnya, ')
        ->and($data['text_start_offset'])->toBe(50)
        ->and($data['occurrence_index'])->toBe(7)
        ->and($data['status'])->toBe('valid')
        ->and($data['resolution_method'])->toBe('apa')
        ->and($data['reference'])->toBe([
            'id' => $reference->getKey(),
            'title' => 'Sistem deteksi plagiarisme',
        ])
        ->and($data)->not->toHaveKey('locations');
});

it('serializes an unpaired citation as hallucination with a null reference', function () {
    $tree = DocumentTree::create();

    $citation = $tree->citation(null, ['citation_text' => '(Andi, 2022)']);

    $status = app(CitationStatusResolver::class)->resolve(CitationResolutionState::Unmatched, null);
    $data = CitationSummaryData::forCitation($citation, $status)->toArray();

    expect($data['status'])->toBe('hallucination')
        ->and($data['resolution_method'])->toBeNull()
        ->and($data['reference'])->toBeNull();
});

it('serializes the citation detail with locations, resolution and candidates', function () {
    $tree = DocumentTree::create();

    $citation = $tree->citation(null, [
        'citation_text' => '(Andi, 2022)',
        'resolution_state' => CitationResolutionState::Unresolved,
        'resolution_method' => CitationResolutionMethod::Apa,
        'resolution_confidence' => 0.72,
    ], locations: 2);

    $reference = $tree->reference(['title' => 'Kandidat utama']);

    CitationResolutionCandidate::factory()->for($citation, 'citation')->for($reference, 'reference')->create([
        'rank' => 1,
        'confidence' => 0.72,
        'method' => CitationResolutionMethod::Apa,
        'match_reason' => 'Kemiripan nama 0.80.',
    ]);

    $citation->load(['locations', 'candidates.reference']);

    $status = CitationStatus::Unresolved;
    $data = CitationDetailData::forCitation($citation, $status)->toArray();

    expect($data['status'])->toBe('unresolved')
        ->and($data['reference'])->toBeNull()
        ->and($data['resolution'])->toBe([
            'state' => 'unresolved',
            'method' => 'apa',
            'confidence' => 0.72,
            'hint_index' => null,
        ])
        ->and($data['locations'])->toHaveCount(2)
        ->and($data['locations'][0])->toHaveKeys([
            'page_number', 'x', 'y', 'width', 'height',
            'page_width', 'page_height', 'coordinate_system', 'location_index',
        ])
        ->and($data['candidates'])->toHaveCount(1)
        ->and($data['candidates'][0]['rank'])->toBe(1)
        ->and($data['candidates'][0]['method'])->toBe('apa')
        ->and($data['candidates'][0]['reference'])->toBe([
            'id' => $reference->getKey(),
            'title' => 'Kandidat utama',
        ]);
});
