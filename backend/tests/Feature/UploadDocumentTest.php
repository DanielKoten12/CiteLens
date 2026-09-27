<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('uploads a PDF and returns document metadata', function () {
    Storage::fake('local');

    $response = $this->post('/api/v1/documents/upload', [
        'file' => UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf'),
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('original_filename', 'laporan.pdf')
        ->assertJsonPath('content_type', 'application/pdf')
        ->assertJsonPath('status', 'uploaded');

    $documentId = $response->json('document_id');
    $storedName = $response->json('stored_filename');

    $this->assertDatabaseHas('documents', [
        'id' => $documentId,
        'filename' => 'laporan.pdf',
        'stored_filename' => $storedName,
        'file_size' => 102400,
        'content_type' => 'application/pdf',
        'status' => 'uploaded',
    ]);
    expect(DB::table('documents')->where('id', $documentId)->value('id'))
        ->toBe($documentId);

    Storage::disk('local')->assertExists('temp/'.$storedName);
});

it('rejects unsupported document formats', function () {
    Storage::fake('local');

    $this->post('/api/v1/documents/upload', [
        'file' => UploadedFile::fake()->create('catatan.txt', 10, 'text/plain'),
    ])
        ->assertStatus(400)
        ->assertJsonValidationErrors('file');
});
