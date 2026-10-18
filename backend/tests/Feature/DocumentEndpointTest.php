<?php

use App\Enums\DocumentStatus;
use App\Models\File;
use App\Models\ReferenceFinding;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentReference;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('lists documents with pagination meta and a derived summary', function () {
    $user = User::factory()->create();
    $completed = buildEndpointDocument($user, ['created_at' => now()->subMinutes(2)]);
    $pending = ResearchedDocument::factory()->for($user)->create(['created_at' => now()->subMinute()]);

    $response = $this->actingAs($user)->getJson('/api/v1/documents');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'status', 'progress', 'summary', 'created_at', 'updated_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonPath('data.0.id', $pending->getKey())
        ->assertJsonPath('data.0.summary', null)
        ->assertJsonPath('data.1.id', $completed->getKey())
        ->assertJsonPath('data.1.summary.total_references', 1)
        ->assertJsonPath('data.1.summary.valid', 1)
        ->assertJsonPath('data.1.summary.total_citations', 1)
        ->assertJsonPath('data.1.summary.valid_citations', 1)
        ->assertJsonPath('data.1.summary.unresolved_citations', 0)
        ->assertJsonPath('data.1.summary.hallucination_citations', 0)
        ->assertJsonPath('meta.total', 2);
});

it('applies the status and search filters', function () {
    $user = User::factory()->create();
    $completed = buildEndpointDocument($user);
    $failed = ResearchedDocument::factory()->for($user)->failed()->create(['name' => 'Gagal.pdf']);

    $this->actingAs($user)->getJson('/api/v1/documents?status=completed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $completed->getKey());

    $this->actingAs($user)->getJson('/api/v1/documents?q=Gagal')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $failed->getKey());
});

it('rejects invalid list filters with 422', function (string $query) {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/documents?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
})->with([
    'invalid status' => 'status=bogus',
    'invalid sort' => 'sort=name',
    'per_page too large' => 'per_page=101',
    'per_page too small' => 'per_page=0',
]);

it('returns full detail with a summary and file for a completed document', function () {
    $user = User::factory()->create();
    $document = buildEndpointDocument($user);
    File::factory()->for($document, 'fileable')->create(['filename' => 'laporan.pdf']);

    $this->actingAs($user)->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $document->getKey())
        ->assertJsonPath('data.summary.total_references', 1)
        ->assertJsonPath('data.file.filename', 'laporan.pdf')
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'status', 'progress', 'current_step', 'error',
                'file' => ['filename', 'mime_type', 'size', 'url'],
                'summary', 'created_at', 'updated_at',
            ],
        ]);
});

it('returns a null summary for a non-completed document', function () {
    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->processing()->create();

    $this->actingAs($user)->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.summary', null)
        ->assertJsonPath('data.status', DocumentStatus::Processing->value);
});

it('returns the lightweight status payload', function () {
    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->processing()->create();

    $this->actingAs($user)->getJson("/api/v1/documents/{$document->getKey()}/status")
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'status', 'progress', 'current_step', 'error', 'updated_at']])
        ->assertJsonMissingPath('data.file')
        ->assertJsonMissingPath('data.summary')
        ->assertJsonPath('data.id', $document->getKey())
        ->assertJsonPath('data.status', DocumentStatus::Processing->value);
});

it('produces the same summary in the list and the detail', function () {
    $user = User::factory()->create();
    $document = buildEndpointDocument($user);

    $listSummary = $this->actingAs($user)->getJson('/api/v1/documents')->json('data.0.summary');
    $detailSummary = $this->actingAs($user)->getJson("/api/v1/documents/{$document->getKey()}")->json('data.summary');

    expect($listSummary)->toBe($detailSummary);
});

it('returns 404 for a malformed uuid', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/documents/not-a-uuid')->assertStatus(404);
});

it('walks the document lifecycle end to end', function () {
    Storage::fake('local');
    Bus::fake();

    $user = User::factory()->create();

    $id = $this->actingAs($user)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf'),
    ])->assertStatus(202)->json('data.id');

    $this->actingAs($user)->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.summary', null);

    $this->actingAs($user)->getJson("/api/v1/documents/{$id}/status")
        ->assertOk()
        ->assertJsonPath('data.status', DocumentStatus::Pending->value);

    $this->actingAs($user)->getJson("/api/v1/documents/{$id}")
        ->assertOk()
        ->assertJsonPath('data.summary', null);

    $this->actingAs($user)->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

    $this->actingAs($user)->getJson("/api/v1/documents/{$id}")->assertStatus(404);
});

/**
 * A completed document with one valid reference, one paired citation and a summary.
 *
 * @param  array<string, mixed>  $attributes
 */
function buildEndpointDocument(User $user, array $attributes = []): ResearchedDocument
{
    $document = ResearchedDocument::factory()->for($user)->completed()->create($attributes);

    $reference = ResearchedDocumentReference::factory()->for($document)->create();
    ReferenceFinding::factory()->forReference($reference)->valid()->create();
    ResearchedDocumentCitation::factory()->for($document)->pairedTo($reference)->create();

    return $document;
}
