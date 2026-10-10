<?php

namespace App\Http\Controllers\Api;

use App\Data\Reference\ReferenceDetailData;
use App\Data\Reference\ReferenceSummaryData;
use App\Data\ReferenceFinding\ReferenceFindingReviewData;
use App\Enums\ReferenceFindingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\ListReferencesRequest;
use App\Http\Requests\Reference\UpdateReferenceFindingRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ResearchedDocumentReference;
use App\Services\Ownership\OwnedResourceFinder;
use App\Services\Reference\ReferenceQueryService;
use App\Services\ReferenceFinding\ReferenceFindingReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference listing, detail and manual review (`docs/API_SPEC.md` §5).
 *
 * Transport only: ownership by {@see OwnedResourceFinder}, queries by the
 * reference services, validation by the FormRequests.
 */
final class ReferenceController extends Controller
{
    public function __construct(
        private readonly ReferenceQueryService $queryService,
        private readonly ReferenceFindingReviewService $reviewService,
        private readonly OwnedResourceFinder $finder,
    ) {}

    /**
     * Paginated bibliography of a document (`GET /documents/{document}/references`).
     */
    public function index(ListReferencesRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $paginator = $this->queryService->paginate(
            $model,
            $request->status(),
            $request->hasDoi(),
            $request->perPage(),
        );

        $paginator->through(
            fn (ResearchedDocumentReference $reference): ReferenceSummaryData => ReferenceSummaryData::forReference($reference),
        );

        return ApiResponse::collection($paginator, ReferenceSummaryData::class);
    }

    /**
     * Reference detail with finding, candidates, locations and resolved
     * citations (`GET /references/{reference}`).
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $model = $this->finder->reference($request->user(), $reference);
        $model->load(['finding.candidates', 'locations', 'citations']);

        return ApiResponse::single(ReferenceDetailData::forReference($model));
    }

    /**
     * Manual verdict override (`PATCH /references/{reference}/finding`).
     */
    public function updateFinding(UpdateReferenceFindingRequest $request, string $reference): JsonResponse
    {
        $model = $this->finder->reference($request->user(), $reference);

        $finding = $this->reviewService->review(
            $model,
            ReferenceFindingStatus::from($request->validated('status')),
            $request->validated('selected_candidate_id'),
            $request->validated('reason'),
            $request->user(),
        );

        return ApiResponse::single(
            ReferenceFindingReviewData::fromModel($finding),
            'Status referensi diperbarui.',
        );
    }
}
