<?php

use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/documents/upload', [UploadController::class, 'upload'])
        ->name('api.v1.documents.upload');
});
