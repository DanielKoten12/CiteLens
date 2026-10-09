<?php

namespace App\Http\Controllers\Api;

use App\Data\Citation\CitationDetailData;
use App\Data\Citation\CitationPairingData;
use App\Data\Citation\CitationSummaryData;
use App\Enums\CitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Citation\ListCitationsRequest;
use App\Http\Requests\Citation\UpdateCitationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ResearchedDocumentCitation;
use App\Services\Citation\CitationPairingService;
use App\Services\Citation\CitationQueryService;
use App\Services\Citations\CitationStatusResolver;
use App\Services\Ownership\OwnedResourceFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Citation listing, detail and manual pairing (`docs/API_SPEC.md` §6).
 *
 * The citation status is always derived through {@see CitationStatusResolver};
 * no controller re-implements the mapping.
 */
final class CitationController extends Controller
{
    public function __construct(
        private readonly CitationQueryService $queryService,
        private readonly CitationPairingService $pairingService,
        private readonly CitationStatusResolver $resolver,
        private readonly OwnedResourceFinder $finder,
    ) {}

    /**
     * Paginated citation list with the derived status (`GET /documents/{document}/citations`).
     */
    public function index(ListCitationsRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);
        $referenceId = $request->referenceId();

        // Same-document guarantee for the filter, after ownership (D-05-10).
        $this->pairingService->assertReferenceBelongsToDocument($model, $referenceId);

        $paginator = $this->queryService->paginate(
            $model,
            $request->status(),
            $referenceId,
            $request->perPage(),
        );

        $paginator->through(
            fn (ResearchedDocumentCitation $citation): CitationSummaryData => CitationSummaryData::forCitation(
                $citation,
                $this->statusFor($citation),
            ),
        );

        return ApiResponse::collection($paginator, CitationSummaryData::class);
    }

    /**
     * Citation detail with highlight locations (`GET /citations/{citation}`).
     */
    public function show(Request $request, string $citation): JsonResponse
    {
        $model = $this->finder->citation($request->user(), $citation);
        $model->load(['reference.finding', 'locations']);

        return ApiResponse::single(CitationDetailData::forCitation($model, $this->statusFor($model)));
    }

    /**
     * Pair or unpair a citation (`PATCH /citations/{citation}`).
     */
    public function update(UpdateCitationRequest $request, string $citation): JsonResponse
    {
        $model = $this->finder->citation($request->user(), $citation);

        $paired = $this->pairingService->pair(
            $model,
            $request->validated('researched_document_reference_id'),
        )->load('reference.finding');

        $isPaired = $paired->researched_document_reference_id !== null;

        return ApiResponse::single(
            CitationPairingData::forCitation($paired, $this->statusFor($paired)),
            $isPaired ? 'Sitasi berhasil ditautkan.' : 'Tautan sitasi berhasil dilepaskan.',
        );
    }

    /**
     * Derive the canonical status exactly once per response item.
     */
    private function statusFor(ResearchedDocumentCitation $citation): CitationStatus
    {
        return $this->resolver->resolve(
            $citation->researched_document_reference_id !== null,
            $citation->reference?->finding?->status,
        );
    }
}
