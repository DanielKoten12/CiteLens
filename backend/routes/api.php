<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        // Upload is limited to 10/minute/user (`docs/API_SPEC.md` §2.9).
        Route::middleware('throttle:documents')->group(function (): void {
            Route::post('/documents', [UploadController::class, 'upload'])->name('documents.store');
        });

        // History list.
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');

        // Detail and status polling. The status route carries the tighter
        // `document-status` limiter (120/minute/user).
        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->whereUuid('document')
            ->name('documents.show');

        Route::get('/documents/{document}/status', [DocumentController::class, 'status'])
            ->middleware('throttle:document-status')
            ->whereUuid('document')
            ->name('documents.status');
    });
});
