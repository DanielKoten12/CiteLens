<?php

use App\Models\ReferenceFinding;
use Tests\Support\DocumentTree;

/**
 * A document with one item per feed type, one excluded reference, one excluded
 * citation, and one offset-less item to exercise nulls-last ordering.
 *
 * @return array<string, mixed>
 */
function findingsFixture(): array
{
    $tree = DocumentTree::create();

    $invalid = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150], locations: 1);
    $invalidFinding = ReferenceFinding::factory()->forReference($invalid)->invalid()
        ->create(['reason' => 'DOI menunjuk ke publikasi yang berbeda.']);

    $suspicious = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    $suspiciousFinding = ReferenceFinding::factory()->forReference($suspicious)->suspicious()->create();

    $notFound = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 350]);
    $notFoundFinding = ReferenceFinding::factory()->forReference($notFound)->notFound()->create();

    $pending = $tree->reference(['text_start_offset' => 400, 'text_end_offset' => 450]);
    $pendingFinding = ReferenceFinding::factory()->forReference($pending)->pending()->create();

    $valid = $tree->reference(['text_start_offset' => 500, 'text_end_offset' => 550]);
    ReferenceFinding::factory()->forReference($valid)->valid()->create();

    $unreliable = $tree->citation($invalid, [
        'citation_text' => '(Invalid, 2020)',
        'text_start_offset' => 120,
        'text_end_offset' => 130,
    ]);

    $tree->citation($valid, [
        'citation_text' => '(Valid, 2020)',
        'text_start_offset' => 520,
        'text_end_offset' => 530,
    ]);

    $hallucination = $tree->citation(null, [
        'citation_text' => '(Andi, 2022)',
        'text_start_offset' => null,
        'text_end_offset' => null,
    ], locations: 1);

    return [
        'tree' => $tree,
        'user' => $tree->user,
        'base' => "/api/v1/documents/{$tree->document->getKey()}/findings",
        'invalid' => $invalid,
        'invalidFinding' => $invalidFinding,
        'suspiciousFinding' => $suspiciousFinding,
        'notFoundFinding' => $notFoundFinding,
        'pendingFinding' => $pendingFinding,
        'unreliable' => $unreliable,
        'hallucination' => $hallucination,
    ];
}

it('returns every problem type with the canonical ids, order and severities', function () {
    $fixture = findingsFixture();

    $response = $this->actingAs($fixture['user'])->getJson($fixture['base']);

    $response->assertOk()->assertJsonPath('meta.total', 6);

    $data = collect($response->json('data'))->keyBy('id');

    expect($data->keys()->all())->toBe([
        "ref-finding:{$fixture['invalidFinding']->getKey()}",
        "citation:{$fixture['unreliable']->getKey()}",
        "ref-finding:{$fixture['suspiciousFinding']->getKey()}",
        "ref-finding:{$fixture['notFoundFinding']->getKey()}",
        "ref-finding:{$fixture['pendingFinding']->getKey()}",
        "citation:{$fixture['hallucination']->getKey()}",
    ]);

    $invalid = $data["ref-finding:{$fixture['invalidFinding']->getKey()}"];
    $unreliable = $data["citation:{$fixture['unreliable']->getKey()}"];

    expect($invalid['type'])->toBe('reference_invalid')
        ->and($invalid['severity'])->toBe('high')
        ->and($invalid['message'])->toBe('DOI menunjuk ke publikasi yang berbeda.')
        ->and($invalid['reference_id'])->toBe($fixture['invalid']->getKey())
        ->and($invalid['citation_id'])->toBeNull()
        // `citation_unreliable` inherits the paired finding's severity.
        ->and($unreliable['type'])->toBe('citation_unreliable')
        ->and($unreliable['severity'])->toBe('high')
        ->and($unreliable['reference_id'])->toBe($fixture['invalid']->getKey())
        ->and($unreliable['citation_id'])->toBe($fixture['unreliable']->getKey());
});

it('maps the canonical severities and never emits low', function () {
    $fixture = findingsFixture();

    $data = collect($this->actingAs($fixture['user'])->getJson($fixture['base'])->json('data'))->keyBy('id');

    expect($data["ref-finding:{$fixture['suspiciousFinding']->getKey()}"]['severity'])->toBe('medium')
        ->and($data["ref-finding:{$fixture['notFoundFinding']->getKey()}"]['severity'])->toBe('high')
        ->and($data["ref-finding:{$fixture['pendingFinding']->getKey()}"]['severity'])->toBe('info')
        ->and($data["citation:{$fixture['hallucination']->getKey()}"]['severity'])->toBe('high');

    expect(collect($data->pluck('severity'))->unique()->contains('low'))->toBeFalse();
});

it('filters the feed by type and severity', function () {
    $fixture = findingsFixture();

    $this->actingAs($fixture['user'])->getJson($fixture['base'].'?type=reference_pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', "ref-finding:{$fixture['pendingFinding']->getKey()}");

    $this->actingAs($fixture['user'])->getJson($fixture['base'].'?type=citation_hallucination')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', "citation:{$fixture['hallucination']->getKey()}");

    $this->actingAs($fixture['user'])->getJson($fixture['base'].'?severity=medium')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($fixture['user'])->getJson($fixture['base'].'?severity=low')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('rejects invalid filters with 422', function (string $query) {
    $fixture = findingsFixture();

    $this->actingAs($fixture['user'])->getJson($fixture['base'].'?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'invalid type' => 'type=bogus',
    'invalid severity' => 'severity=critical',
    'per_page too large' => 'per_page=101',
]);

it('includes highlight locations for references and citations', function () {
    $fixture = findingsFixture();

    $data = collect($this->actingAs($fixture['user'])->getJson($fixture['base'])->json('data'))->keyBy('id');

    $referenceLocations = $data["ref-finding:{$fixture['invalidFinding']->getKey()}"]['locations'];
    $citationLocations = $data["citation:{$fixture['hallucination']->getKey()}"]['locations'];

    expect($referenceLocations)->toHaveCount(1)
        ->and($referenceLocations[0])->toHaveKeys([
            'page_number', 'x', 'y', 'width', 'height',
            'page_width', 'page_height', 'coordinate_system', 'location_index',
        ])
        ->and($citationLocations)->toHaveCount(1)
        ->and($citationLocations[0]['coordinate_system'])->toBe('pdf_points_top_left');
});

it('sorts offset-less items last', function () {
    $fixture = findingsFixture();

    $data = $this->actingAs($fixture['user'])->getJson($fixture['base'])->json('data');

    expect($data[0]['id'])->toBe("ref-finding:{$fixture['invalidFinding']->getKey()}")
        ->and($data[array_key_last($data)]['id'])->toBe("citation:{$fixture['hallucination']->getKey()}")
        ->and($data[array_key_last($data)]['start_offset'])->toBeNull();
});

it('paginates the union with correct meta', function () {
    $fixture = findingsFixture();

    $response = $this->actingAs($fixture['user'])->getJson($fixture['base'].'?per_page=2');

    $response->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(2, 'data');
});

it('returns an empty feed for a document without findings', function () {
    $tree = DocumentTree::create();

    $this->actingAs($tree->user)->getJson("/api/v1/documents/{$tree->document->getKey()}/findings")
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('never emits a valid reference or citation', function () {
    $fixture = findingsFixture();

    $data = $this->actingAs($fixture['user'])->getJson($fixture['base'])->json('data');

    $types = collect($data)->pluck('type')->all();

    expect($types)->not->toContain('reference_valid')
        ->and($types)->not->toContain('citation_valid');
});
