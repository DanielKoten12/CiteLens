<?php

use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('/documents', [UploadController::class, 'upload'])
        ->name('documents.store');
});
