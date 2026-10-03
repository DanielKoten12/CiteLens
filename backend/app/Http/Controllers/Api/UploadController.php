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
 * The route is protected by `auth:sanctum`, so the authenticated user is always
 * present here.
 *
 * TODO(integrasi): dispatch AnalyzeDocumentJob. Saat ini endpoint hanya
 * menyimpan dokumen (status `pending`, step `queued`) tanpa menjalankan pipeline.
 */
class UploadController extends Controller
{
    public function __construct(
        private DocumentUploadService $documentUploadService,
    ) {}

    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        $document = $this->documentUploadService->handle(
            user: $request->user(),
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
