<?php

use App\Enums\DocumentStatus;
use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->tree = DocumentTree::create();
    $this->user = $this->tree->user;
    $this->base = "/api/v1/documents/{$this->tree->document->getKey()}/references";
});

it('lists references with filters and pagination', function () {
    $valid = $this->tree->reference([
        'doi' => '10.1000/valid',
        'text_start_offset' => 100,
        'text_end_offset' => 150,
    ]);
    ReferenceFinding::factory()->forReference($valid)->valid()->create();

    $pending = $this->tree->reference([
        'doi' => null,
        'text_start_offset' => 200,
        'text_end_offset' => 250,
    ]);

    $this->actingAs($this->user)->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'raw_text', 'doi', 'title', 'authors', 'publication_name', 'publication_year', 'text_start_offset', 'text_end_offset', 'finding']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $valid->getKey())
        ->assertJsonPath('data.0.finding.status', 'valid')
        ->assertJsonPath('data.1.id', $pending->getKey())
        ->assertJsonPath('data.1.finding', null);

    $this->actingAs($this->user)->getJson($this->base.'?status=valid')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $valid->getKey());

    // A reference without a finding is still `pending` (broad plan §3.3).
    $this->actingAs($this->user)->getJson($this->base.'?status=pending')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pending->getKey());

    $this->actingAs($this->user)->getJson($this->base.'?has_doi=true')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $valid->getKey());

    $this->actingAs($this->user)->getJson($this->base.'?has_doi=false')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pending->getKey());

    // `1/0` are accepted equivalents of `true/false`.
    $this->actingAs($this->user)->getJson($this->base.'?has_doi=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $valid->getKey());

    $this->actingAs($this->user)->getJson($this->base.'?has_doi=0')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pending->getKey());
});

it('rejects invalid list filters with 422', function (string $query) {
    $this->actingAs($this->user)->getJson($this->base.'?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'invalid status' => 'status=bogus',
    'invalid has_doi' => 'has_doi=maybe',
    'per_page too large' => 'per_page=101',
    'per_page too small' => 'per_page=0',
]);

it('returns full reference detail with finding, candidates, locations and citations', function () {
    $reference = $this->tree->reference(['text_start_offset' => 100, 'text_end_offset' => 250], locations: 1);
    $finding = $this->tree->finding($reference, [
        'status' => ReferenceFindingStatus::Suspicious,
        'confidence' => 0.63,
    ], 1);
    $citation = $this->tree->citation($reference, [
        'citation_text' => '(Koten, 2023)',
        'occurrence_index' => 4,
    ]);

    $this->actingAs($this->user)->getJson("/api/v1/references/{$reference->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $reference->getKey())
        ->assertJsonPath('data.finding.id', $finding->getKey())
        ->assertJsonPath('data.finding.status', 'suspicious')
        ->assertJsonPath('data.finding.is_manual', false)
        ->assertJsonPath('data.finding.reviewed_by', null)
        ->assertJsonPath('data.finding.candidates.0.id', $finding->selected_candidate_id)
        ->assertJsonPath('data.locations.0.page_number', $reference->locations->first()->page_number)
        ->assertJsonPath('data.locations.0.coordinate_system', 'pdf_points_top_left')
        ->assertJsonPath('data.citations.0.id', $citation->getKey())
        ->assertJsonPath('data.citations.0.citation_text', '(Koten, 2023)')
        ->assertJsonPath('data.citations.0.occurrence_index', 4);
});

it('returns a null finding for an un-evaluated reference', function () {
    $reference = $this->tree->reference();

    $this->actingAs($this->user)->getJson("/api/v1/references/{$reference->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.finding', null)
        ->assertJsonPath('data.locations', [])
        ->assertJsonPath('data.citations', []);
});

it('updates a finding and audits the reviewer', function () {
    $reference = $this->tree->reference();
    $finding = $this->tree->finding($reference, ['status' => ReferenceFindingStatus::Suspicious], 1);

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", [
        'status' => 'valid',
        'selected_candidate_id' => $finding->selected_candidate_id,
        'reason' => 'Diverifikasi manual oleh pengguna.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.is_manual', true)
        ->assertJsonPath('data.reviewed_by', $this->user->getKey())
        ->assertJsonPath('data.selected_candidate_id', $finding->selected_candidate_id)
        ->assertJsonPath('message', 'Status referensi diperbarui.');

    expect($finding->refresh())
        ->is_manual->toBeTrue()
        ->reviewed_at->not->toBeNull()
        ->reason->toBe('Diverifikasi manual oleh pengguna.');
});

it('creates a manual finding through the endpoint', function () {
    $reference = $this->tree->reference();

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", [
        'status' => 'not_found',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'not_found')
        ->assertJsonPath('data.confidence', null)
        ->assertJsonPath('data.is_manual', true);

    expect($reference->fresh()->finding->is_manual)->toBeTrue();
});

it('rejects patch bodies with an invalid or pending status', function (array $body) {
    $reference = $this->tree->reference();

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'bogus status' => [['status' => 'bogus']],
    'pending status' => [['status' => 'pending']],
    'missing status' => [[]],
]);

it('rejects a candidate that belongs to another finding', function () {
    $reference = $this->tree->reference(['text_start_offset' => 100, 'text_end_offset' => 150]);

    $other = $this->tree->reference(['text_start_offset' => 200, 'text_end_offset' => 250]);
    $otherFinding = $this->tree->finding($other, ['status' => ReferenceFindingStatus::Suspicious], 1);

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", [
        'status' => 'valid',
        'selected_candidate_id' => $otherFinding->selected_candidate_id,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.details.selected_candidate_id.0', 'Kandidat yang dipilih tidak valid untuk temuan ini.');
});

it('returns 409 when reviewing while the document is processing', function () {
    $this->tree->document->update(['status' => DocumentStatus::Processing]);
    $reference = $this->tree->reference();

    $this->actingAs($this->user)->patchJson("/api/v1/references/{$reference->getKey()}/finding", [
        'status' => 'valid',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', 'Status referensi tidak dapat diubah saat analisis sedang berjalan.');
});
