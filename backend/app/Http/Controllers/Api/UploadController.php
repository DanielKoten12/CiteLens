<?php

namespace App\Http\Controllers\Api;

use App\Data\ResearchedDocument\ResearchedDocumentDetailData;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Http\Responses\ApiResponse;
use App\Jobs\AnalyzeDocumentJob;
use App\Services\DocumentUploadService;
use Illuminate\Http\JsonResponse;

/**
 * {@see DocumentUploadService}.
 *
 * The route is protected by `auth:sanctum`, so the authenticated user is always
 * present here. The analysis pipeline is dispatched asynchronously after the
 * upload commits; this endpoint never runs it inline (`docs/API_SPEC.md` §4/§10).
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

        AnalyzeDocumentJob::dispatch($document->id)->afterCommit();

        return ApiResponse::accepted(
            data: ResearchedDocumentDetailData::from($document),
            message: 'Dokumen berhasil diunggah. Analisis sedang diproses.',
        );
    }
}
