<?php

use App\Models\ReferenceFinding;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->tree = DocumentTree::create();
    $this->user = $this->tree->user;
});

it('pairs an unpaired citation with a same-document reference', function () {
    $reference = $this->tree->reference();
    ReferenceFinding::factory()->forReference($reference)->valid()->create();
    $citation = $this->tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [
        'researched_document_reference_id' => $reference->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $citation->getKey())
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.reference.id', $reference->getKey())
        ->assertJsonPath('message', 'Sitasi berhasil ditautkan.');

    expect($citation->refresh()->researched_document_reference_id)->toBe($reference->getKey());
});

it('derives unreliable when pairing with an invalid reference', function () {
    $reference = $this->tree->reference();
    ReferenceFinding::factory()->forReference($reference)->invalid()->create();
    $citation = $this->tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [
        'researched_document_reference_id' => $reference->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'unreliable');
});

it('rejects pairing with a reference of another document', function () {
    $other = DocumentTree::create();
    $foreignReference = $other->reference();

    $citation = $this->tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [
        'researched_document_reference_id' => $foreignReference->getKey(),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath(
            'error.details.researched_document_reference_id.0',
            'The selected reference is invalid for this document.',
        );

    expect($citation->refresh()->researched_document_reference_id)->toBeNull();
});

it('unpairs a citation back to hallucination', function () {
    $reference = $this->tree->reference();
    ReferenceFinding::factory()->forReference($reference)->valid()->create();
    $citation = $this->tree->citation($reference, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [
        'researched_document_reference_id' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'hallucination')
        ->assertJsonPath('data.reference', null)
        ->assertJsonPath('message', 'Tautan sitasi berhasil dilepaskan.');

    expect($citation->refresh()->researched_document_reference_id)->toBeNull();
});

it('rejects a body without the pairing field', function () {
    $citation = $this->tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('rejects a malformed reference id', function () {
    $citation = $this->tree->citation(null, ['citation_text' => '(Koten, 2023)']);

    $this->actingAs($this->user)->patchJson("/api/v1/citations/{$citation->getKey()}", [
        'researched_document_reference_id' => 'not-a-uuid',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});
