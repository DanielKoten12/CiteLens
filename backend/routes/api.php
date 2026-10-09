<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CitationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    /*
     * Status polling has its own documented limit (120/min/user, `docs/API_SPEC.md`
     * §2.9) and is therefore declared outside the general `throttle:api` group:
     * stacking the 60/min general limiter would make the 120/min limit
     * unreachable and contradict the contract.
     */
    Route::middleware(['auth:sanctum', 'throttle:document-status'])->group(function (): void {
        Route::get('/documents/{document}/status', [DocumentController::class, 'status'])
            ->whereUuid('document')
            ->name('documents.status');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Upload is limited to 10/minute/user (`docs/API_SPEC.md` §2.9); the
        // general `api` limiter (60/min) is looser, so the tighter one wins.
        Route::middleware('throttle:documents')->group(function (): void {
            Route::post('/documents', [UploadController::class, 'upload'])->name('documents.store');
        });

        // History list.
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');

        // Purge the entire history. Declared separately from the wildcard delete
        // so it can never be shadowed by a future `{document}` pattern.
        Route::delete('/documents', [DocumentController::class, 'purge'])->name('documents.purge');

        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->whereUuid('document')
            ->name('documents.show');

        Route::post('/documents/{document}/retry', [DocumentController::class, 'retry'])
            ->whereUuid('document')
            ->name('documents.retry');

        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
            ->whereUuid('document')
            ->name('documents.destroy');

        // Verification surface (Phase 05, `docs/API_SPEC.md` §5/§6).
        Route::get('/documents/{document}/references', [ReferenceController::class, 'index'])
            ->whereUuid('document')
            ->name('documents.references.index');

        Route::get('/references/{reference}', [ReferenceController::class, 'show'])
            ->whereUuid('reference')
            ->name('references.show');

        Route::patch('/references/{reference}/finding', [ReferenceController::class, 'updateFinding'])
            ->whereUuid('reference')
            ->name('references.finding.update');

        Route::get('/documents/{document}/citations', [CitationController::class, 'index'])
            ->whereUuid('document')
            ->name('documents.citations.index');

        Route::get('/citations/{citation}', [CitationController::class, 'show'])
            ->whereUuid('citation')
            ->name('citations.show');

        Route::patch('/citations/{citation}', [CitationController::class, 'update'])
            ->whereUuid('citation')
            ->name('citations.update');
    });
});
