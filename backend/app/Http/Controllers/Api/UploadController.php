<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Http\Resources\ResearchedDocumentResource;
use App\Services\DocumentUploadService;
use Illuminate\Http\JsonResponse;

/**
 * {@see DocumentUploadService}.
 *
 * TODO(integrasi): pasang middleware auth:sanctum pada route setelah Sanctum
 * terpasang.
 * TODO(integrasi): dispatch AnalyzeDocumentJob di sini. Saat ini endpoint hanya
 * menyimpan dokumen (status `pending`, step `queued`) tanpa menjalankan pipeline.
 */
class UploadController extends Controller
{
    public function upload(
        UploadDocumentRequest $request,
        DocumentUploadService $service,
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], 401);
        }

        $document = $service->handle(
            user: $user,
            file: $request->file('file'),
            name: $request->validated('name'),
        );

        return (new ResearchedDocumentResource($document->load('files')))
            ->additional(['message' => 'Dokumen berhasil diunggah.'])
            ->response()
            ->setStatusCode(202);
    }
}
