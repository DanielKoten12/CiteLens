<?php

use App\Data\Citation\CitationDetailData;
use App\Data\Citation\CitationSummaryData;
use App\Enums\CitationStatus;
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
    ]);

    $citation->load('reference.finding');

    $status = app(CitationStatusResolver::class)->resolve(true, $citation->reference->finding->status);
    $data = CitationSummaryData::forCitation($citation, $status)->toArray();

    expect($data['id'])->toBe($citation->getKey())
        ->and($data['citation_text'])->toBe('(Koten, 2023)')
        ->and($data['citation_marker'])->toBe('Koten, 2023')
        ->and($data['context_before'])->toBe('Sebelumnya, ')
        ->and($data['text_start_offset'])->toBe(50)
        ->and($data['occurrence_index'])->toBe(7)
        ->and($data['status'])->toBe('valid')
        ->and($data['reference'])->toBe([
            'id' => $reference->getKey(),
            'title' => 'Sistem deteksi plagiarisme',
        ])
        ->and($data)->not->toHaveKey('locations');
});

it('serializes an unpaired citation as hallucination with a null reference', function () {
    $tree = DocumentTree::create();

    $citation = $tree->citation(null, ['citation_text' => '(Andi, 2022)']);

    $status = app(CitationStatusResolver::class)->resolve(false, null);
    $data = CitationSummaryData::forCitation($citation, $status)->toArray();

    expect($data['status'])->toBe('hallucination')
        ->and($data['reference'])->toBeNull();
});

it('serializes the citation detail with locations', function () {
    $tree = DocumentTree::create();

    $citation = $tree->citation(null, ['citation_text' => '(Andi, 2022)'], locations: 2);
    $citation->load('locations');

    $status = CitationStatus::Hallucination;
    $data = CitationDetailData::forCitation($citation, $status)->toArray();

    expect($data['status'])->toBe('hallucination')
        ->and($data['reference'])->toBeNull()
        ->and($data['locations'])->toHaveCount(2)
        ->and($data['locations'][0])->toHaveKeys([
            'page_number', 'x', 'y', 'width', 'height',
            'page_width', 'page_height', 'coordinate_system', 'location_index',
        ]);
});
