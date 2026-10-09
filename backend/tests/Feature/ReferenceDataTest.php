<?php

use App\Data\Reference\ReferenceDetailData;
use App\Data\Reference\ReferenceSummaryData;
use App\Enums\ReferenceFindingStatus;
use Tests\Support\DocumentTree;

it('serializes a reference summary with its finding preview', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference([
        'title' => 'Sistem deteksi plagiarisme',
        'doi' => '10.1000/example',
        'publication_year' => 2023,
        'text_start_offset' => 100,
        'text_end_offset' => 250,
    ]);

    $finding = $tree->finding($reference, [
        'status' => ReferenceFindingStatus::Valid,
        'confidence' => 0.95,
    ], 1);

    $reference->load('finding');

    $data = ReferenceSummaryData::forReference($reference)->toArray();

    expect($data['id'])->toBe($reference->getKey())
        ->and($data['title'])->toBe('Sistem deteksi plagiarisme')
        ->and($data['doi'])->toBe('10.1000/example')
        ->and($data['publication_year'])->toBe(2023)
        ->and($data['text_start_offset'])->toBe(100)
        ->and($data['text_end_offset'])->toBe(250)
        ->and($data['finding']['id'])->toBe($finding->getKey())
        ->and($data['finding']['status'])->toBe('valid')
        ->and($data['finding']['confidence'])->toBe(0.95)
        ->and($data['finding']['selected_candidate_id'])->toBe($finding->selected_candidate_id)
        ->and($data['finding'])->not->toHaveKey('candidates');
});

it('serializes a reference summary with a null finding', function () {
    $tree = DocumentTree::create();
    $reference = $tree->reference();

    $data = ReferenceSummaryData::forReference($reference)->toArray();

    expect($data['finding'])->toBeNull();
});

it('serializes the full reference detail with finding, candidates, locations and citations', function () {
    $tree = DocumentTree::create();

    $reference = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 250], locations: 2);

    $finding = $tree->finding($reference, [
        'status' => ReferenceFindingStatus::Suspicious,
        'confidence' => 0.63,
        'reason' => 'Judul pada metadata Crossref memiliki perbedaan.',
    ], 2);

    $citation = $tree->citation($reference, [
        'citation_text' => '(Koten, 2023)',
        'occurrence_index' => 4,
    ]);

    $reference->load(['finding.candidates', 'locations', 'citations']);

    $data = ReferenceDetailData::forReference($reference)->toArray();

    expect($data['id'])->toBe($reference->getKey())
        ->and($data['finding']['id'])->toBe($finding->getKey())
        ->and($data['finding']['is_manual'])->toBeFalse()
        ->and($data['finding']['reviewed_by'])->toBeNull()
        ->and($data['finding']['reviewed_at'])->toBeNull()
        ->and($data['finding']['candidates'])->toHaveCount(2)
        ->and($data['finding']['candidates'][0])->toHaveKeys([
            'id', 'rank', 'confidence', 'doi', 'title', 'authors',
            'publication_name', 'publication_year', 'url', 'match_reason',
        ])
        ->and($data['locations'])->toHaveCount(2)
        ->and($data['locations'][0])->toHaveKeys([
            'page_number', 'x', 'y', 'width', 'height',
            'page_width', 'page_height', 'coordinate_system', 'location_index',
        ])
        ->and($data['citations'])->toHaveCount(1)
        ->and($data['citations'][0])->toBe([
            'id' => $citation->getKey(),
            'citation_text' => '(Koten, 2023)',
            'occurrence_index' => 4,
        ]);
});
