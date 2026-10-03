<?php

namespace App\Http\Controllers\Api;

use App\Data\ResearchedDocument\ResearchedDocumentDetailData;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
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
    public function __construct(
        private DocumentUploadService $documentUploadService,
    ) {}

    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        $user = $request->user();

        // TODO: Remove and use auth middleware after implementing auth routes
        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], 401);
        }

        $document = $this->documentUploadService->handle(
            user: $user,
            file: $request->file('file'),
            name: $request->validated('name'),
        );

        $document->load('file');

        return response()->json([
            'data' => ResearchedDocumentDetailData::from($document),
            'message' => 'Dokumen berhasil diunggah.',
        ], 202);
    }
}
