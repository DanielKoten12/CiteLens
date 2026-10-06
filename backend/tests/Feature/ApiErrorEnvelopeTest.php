<?php

use App\Exceptions\InferenceUnavailableException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\StateConflictException;
use App\Models\ResearchedDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;

/**
 * The canonical error envelope must hold for framework exceptions too
 * (`docs/API_SPEC.md` §2.5, `docs/SECURITY.md` §3).
 *
 * Test-only routes are registered here (never in `routes/api.php`) and share the
 * `api/v1/*` prefix so the renderer's API guard applies.
 */
beforeEach(function () {
    Route::prefix('api/v1/__errors')->group(function () {
        Route::get('resource-not-found/{type}', function (string $type) {
            throw match ($type) {
                'document' => ResourceNotFoundException::document(),
                'reference' => ResourceNotFoundException::reference(),
                'citation' => ResourceNotFoundException::citation(),
                'report' => ResourceNotFoundException::report(),
            };
        });

        Route::get('state-conflict/{type}', function (string $type) {
            throw match ($type) {
                'retry' => StateConflictException::documentRetryNotAllowed(),
                'report' => StateConflictException::reportNotAllowed(),
            };
        });

        Route::get('inference-unavailable', fn () => throw InferenceUnavailableException::serviceUnavailable());
        Route::get('runtime', fn () => throw new RuntimeException('internal-secret'));
        Route::get('http-exception', fn () => abort(418));
        Route::get('model-not-found', fn () => throw (new ModelNotFoundException)->setModel(ResearchedDocument::class));
        Route::get('uuid/{id}', fn (string $id) => response()->json(['data' => ['id' => $id]]))->whereUuid('id');
    });
});

it('renders per-resource 404 envelopes for foreign or missing resources', function () {
    $cases = [
        'document' => 'Dokumen tidak ditemukan.',
        'reference' => 'Referensi tidak ditemukan.',
        'citation' => 'Sitasi tidak ditemukan.',
        'report' => 'Laporan tidak ditemukan.',
    ];

    foreach ($cases as $type => $message) {
        $this->getJson("/api/v1/__errors/resource-not-found/{$type}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', $message)
            ->assertJsonMissingPath('error.details');
    }
});

it('renders 409 envelopes for invalid state transitions', function () {
    $this->getJson('/api/v1/__errors/state-conflict/retry')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', 'Analisis hanya dapat diulang untuk dokumen yang gagal.');

    $this->getJson('/api/v1/__errors/state-conflict/report')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', 'Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis.');
});

it('renders a safe 503 envelope when inference is unavailable', function () {
    $this->getJson('/api/v1/__errors/inference-unavailable')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'INFERENCE_UNAVAILABLE')
        ->assertJsonPath('error.message', 'Layanan analisis tidak tersedia. Coba lagi nanti.')
        ->assertJsonMissingPath('error.details');
});

it('renders 404 for unknown routes', function () {
    $this->getJson('/api/v1/__missing')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Sumber daya tidak ditemukan.');
});

it('renders 404 for malformed UUID path parameters without touching the database', function () {
    $this->getJson('/api/v1/__errors/uuid/not-a-uuid')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->getJson('/api/v1/__errors/uuid/'.fake()->uuid())
        ->assertOk()
        ->assertJsonPath('data.id', fn (string $id): bool => $id !== '');
});

it('maps method not allowed to a 400 BAD_REQUEST envelope', function () {
    $this->deleteJson('/api/v1/auth/login')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'BAD_REQUEST')
        ->assertJsonPath('error.message', 'Metode permintaan tidak diizinkan.');
});

it('normalizes unknown 4xx statuses to BAD_REQUEST', function () {
    $this->getJson('/api/v1/__errors/http-exception')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'BAD_REQUEST')
        ->assertJsonPath('error.message', 'Permintaan tidak valid.');
});

it('converts model not found exceptions to a 404 envelope', function () {
    $this->getJson('/api/v1/__errors/model-not-found')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Sumber daya tidak ditemukan.');
});

it('renders a generic 500 envelope without leaking internals', function () {
    $response = $this->getJson('/api/v1/__errors/runtime');

    $response
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'SERVER_ERROR')
        ->assertJsonPath('error.message', 'Terjadi kesalahan pada server.')
        ->assertJsonMissingPath('error.details')
        ->assertDontSee('internal-secret');

    expect($response->json('error'))->toBe([
        'code' => 'SERVER_ERROR',
        'message' => 'Terjadi kesalahan pada server.',
    ]);
});
