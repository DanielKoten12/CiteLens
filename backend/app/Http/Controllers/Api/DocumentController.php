<?php

namespace App\Http\Controllers\Api;

use App\Data\ResearchedDocument\ResearchedDocumentDetailData;
use App\Data\ResearchedDocument\ResearchedDocumentStatusData;
use App\Data\ResearchedDocument\ResearchedDocumentSummaryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\ListDocumentsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ResearchedDocument;
use App\Services\Document\DocumentDeletionService;
use App\Services\Document\DocumentLifecycleService;
use App\Services\Document\DocumentQueryService;
use App\Services\Document\DocumentSummaryService;
use App\Services\Ownership\OwnedResourceFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Document lifecycle endpoints (`docs/API_SPEC.md` §4).
 *
 * Transport only: validation is owned by the FormRequests, ownership by
 * {@see OwnedResourceFinder}, queries/counts by the document services. Every
 * UUID is validated by the route's `whereUuid` constraint before reaching here.
 */
final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentQueryService $queryService,
        private readonly DocumentSummaryService $summaryService,
        private readonly DocumentLifecycleService $lifecycleService,
        private readonly DocumentDeletionService $deletionService,
        private readonly OwnedResourceFinder $finder,
    ) {}

    /**
     * Paginated document history (`GET /documents`).
     */
    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $paginator = $this->queryService->paginate(
            $request->user(),
            $request->status(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        );

        // Batched in a constant number of queries, keyed by document id.
        $summaries = $this->summaryService->forDocuments($paginator->items());

        $paginator->through(
            fn (ResearchedDocument $document): ResearchedDocumentSummaryData => ResearchedDocumentSummaryData::forDocument(
                $document,
                $summaries[$document->getKey()] ?? null,
            ),
        );

        return ApiResponse::collection($paginator, ResearchedDocumentSummaryData::class);
    }

    /**
     * Full document detail (`GET /documents/{document}`).
     */
    public function show(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);
        $model->load('file');

        return ApiResponse::single(
            ResearchedDocumentDetailData::forDocument($model, $this->summaryService->forDocument($model)),
        );
    }

    /**
     * Lightweight polling payload (`GET /documents/{document}/status`).
     *
     * Deliberately cheap: no relations, no summary, no counts.
     */
    public function status(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        return ApiResponse::single(ResearchedDocumentStatusData::from($model));
    }

    /**
     * Re-run the analysis for a failed document (`POST /documents/{document}/retry`).
     */
    public function retry(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $fresh = $this->lifecycleService->retry($model);

        return ApiResponse::accepted(
            data: ResearchedDocumentStatusData::from($fresh),
            message: 'Analisis dijadwalkan ulang.',
        );
    }

    /**
     * Hard-delete one document and its cascade (`DELETE /documents/{document}`).
     */
    public function destroy(Request $request, string $document): Response
    {
        $model = $this->finder->document($request->user(), $document);

        $this->deletionService->delete($model);

        return ApiResponse::noContent();
    }

    /**
     * Hard-delete the authenticated user's entire history (`DELETE /documents`).
     */
    public function purge(Request $request): Response
    {
        $this->deletionService->purge($request->user());

        return ApiResponse::noContent();
    }
}
