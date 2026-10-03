<?php

use App\Models\File;
use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('persists an uploaded PDF against the researched document schema', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
    ]);

    $response
        ->assertStatus(202)
        ->assertJsonPath('message', 'Dokumen berhasil diunggah.')
        ->assertJsonPath('data.name', 'laporan.pdf')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.progress', 0)
        ->assertJsonPath('data.current_step', 'queued')
        ->assertJsonPath('data.error', null)
        ->assertJsonPath('data.file.filename', 'laporan.pdf')
        ->assertJsonPath('data.file.mime_type', 'application/pdf')
        ->assertJsonPath('data.file.size', 102400)
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'status',
                'progress',
                'current_step',
                'error',
                'file' => ['filename', 'mime_type', 'size', 'url'],
                'created_at',
                'updated_at',
            ],
            'message',
        ]);

    $documentId = $response->json('data.id');

    expect($documentId)->toBeString()
        ->and($response->json('data.file.url'))->toBeString();

    $this->assertDatabaseHas('researched_documents', [
        'id' => $documentId,
        'user_id' => $user->id,
        'name' => 'laporan.pdf',
        'status' => 'pending',
        'analysis_progress' => 0,
        'analysis_step' => 'queued',
    ]);

    $this->assertDatabaseHas('files', [
        'fileable_type' => ResearchedDocument::class,
        'fileable_id' => $documentId,
        'filename' => 'laporan.pdf',
        'mime_type' => 'application/pdf',
        'size' => 102400,
    ]);

    $path = DB::table('files')->where('fileable_id', $documentId)->value('path');

    Storage::disk('local')->assertExists($path);
});

it('uses the provided name instead of the original filename', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
            'name' => 'Laporan Akhir',
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.name', 'Laporan Akhir')
        ->assertJsonPath('data.file.filename', 'laporan.pdf');
});

it('rejects uploads that are not PDF with 415', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('catatan.txt', 10, 'text/plain'),
        ])
        ->assertStatus(415)
        ->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE')
        ->assertJsonPath('error.message', 'Format file tidak didukung. Hanya PDF yang diterima.')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['file']]]);

    expect(ResearchedDocument::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});

it('rejects uploads larger than 20 MB with 413', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('laporan.pdf', 20481, 'application/pdf'),
        ])
        ->assertStatus(413)
        ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE')
        ->assertJsonPath('error.message', 'Ukuran file melebihi batas 20 MB.');
});

it('rejects a missing file with 422', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/documents', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['file']]]);
});

it('rejects an over-long document name with 422', function () {
    Storage::fake('local');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
            'name' => str_repeat('a', 256),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['name']]]);
});

it('rejects unauthenticated uploads with 401', function () {
    Storage::fake('local');

    $this->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
