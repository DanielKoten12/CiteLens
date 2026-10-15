<?php

use App\Enums\CitationResolutionMethod;
use App\Enums\CitationResolutionState;
use App\Models\CitationResolutionCandidate;
use App\Models\ReferenceFinding;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->tree = DocumentTree::create();
    $this->user = $this->tree->user;
    $this->base = "/api/v1/documents/{$this->tree->document->getKey()}/citations";
});

it('lists citations with the derived status and reference filters', function () {
    $reference = $this->tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);
    ReferenceFinding::factory()->forReference($reference)->valid()->create();

    $paired = $this->tree->citation($reference, [
        'citation_text' => '(Koten, 2023)',
        'text_start_offset' => 100,
        'text_end_offset' => 110,
    ]);
    $unpaired = $this->tree->citation(null, [
        'citation_text' => '(Andi, 2022)',
        'text_start_offset' => 200,
        'text_end_offset' => 210,
    ]);

    $this->actingAs($this->user)->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'citation_text', 'citation_marker', 'context_before', 'context_after', 'text_start_offset', 'text_end_offset', 'occurrence_index', 'status', 'resolution_method', 'reference']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $paired->getKey())
        ->assertJsonPath('data.0.status', 'valid')
        ->assertJsonPath('data.0.reference.id', $reference->getKey())
        ->assertJsonPath('data.1.id', $unpaired->getKey())
        ->assertJsonPath('data.1.status', 'hallucination')
        ->assertJsonPath('data.1.reference', null);

    $this->actingAs($this->user)->getJson($this->base.'?status=hallucination')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $unpaired->getKey());

    $this->actingAs($this->user)->getJson($this->base.'?status=valid')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $paired->getKey());

    $this->actingAs($this->user)->getJson($this->base."?reference_id={$reference->getKey()}")
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $paired->getKey());
});

it('rejects invalid list filters with 422', function (string $query) {
    $this->actingAs($this->user)->getJson($this->base.'?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'invalid status' => 'status=bogus',
    'invalid reference id' => 'reference_id=not-a-uuid',
    'per_page too large' => 'per_page=101',
]);

it('rejects a reference_id filter from another document', function () {
    $other = DocumentTree::create();
    $foreign = $other->reference();

    $this->actingAs($this->user)->getJson($this->base."?reference_id={$foreign->getKey()}")
        ->assertStatus(422)
        ->assertJsonPath('error.details.reference_id.0', 'The selected reference is invalid for this document.');
});

it('returns the citation detail with locations', function () {
    $citation = $this->tree->citation(null, ['citation_text' => '(Andi, 2022)'], locations: 2);

    $this->actingAs($this->user)->getJson("/api/v1/citations/{$citation->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $citation->getKey())
        ->assertJsonPath('data.status', 'hallucination')
        ->assertJsonPath('data.reference', null)
        ->assertJsonPath('data.resolution.state', 'unmatched')
        ->assertJsonPath('data.resolution.method', null)
        ->assertJsonPath('data.candidates', [])
        ->assertJsonPath('data.locations.0.coordinate_system', 'pdf_points_top_left')
        ->assertJsonCount(2, 'data.locations');
});

it('returns the resolution detail and candidates for an unresolved citation', function () {
    $reference = $this->tree->reference(['title' => 'Kandidat utama']);
    $citation = $this->tree->citation(null, [
        'citation_text' => '(Hartini)',
        'resolution_state' => CitationResolutionState::Unresolved,
        'resolution_method' => CitationResolutionMethod::Apa,
        'resolution_confidence' => 0.88,
    ]);

    CitationResolutionCandidate::factory()->for($citation, 'citation')->for($reference, 'reference')->create([
        'rank' => 1,
        'confidence' => 0.88,
        'method' => CitationResolutionMethod::Apa,
    ]);

    $this->actingAs($this->user)->getJson("/api/v1/citations/{$citation->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.status', 'unresolved')
        ->assertJsonPath('data.resolution.state', 'unresolved')
        ->assertJsonPath('data.resolution.method', 'apa')
        ->assertJsonPath('data.resolution.confidence', 0.88)
        ->assertJsonPath('data.candidates.0.rank', 1)
        ->assertJsonPath('data.candidates.0.reference.id', $reference->getKey())
        ->assertJsonPath('data.candidates.0.reference.title', 'Kandidat utama');
});

it('propagates a finding change to every paired citation immediately', function () {
    $reference = $this->tree->reference();
    ReferenceFinding::factory()->forReference($reference)->valid()->create();
    $citation = $this->tree->citation($reference, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->getJson("/api/v1/citations/{$citation->getKey()}")
        ->assertOk()->assertJsonPath('data.status', 'valid');

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", [
        'status' => 'invalid',
    ])->assertOk();

    $this->actingAs($this->user)->getJson("/api/v1/citations/{$citation->getKey()}")
        ->assertOk()->assertJsonPath('data.status', 'unreliable');

    $this->actingAs($this->user)->getJson($this->base.'?status=unreliable')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $citation->getKey());
});
