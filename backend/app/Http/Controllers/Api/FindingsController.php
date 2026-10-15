<?php

namespace App\Http\Controllers\Api;

use App\Data\Finding\FindingHighlightData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finding\ListFindingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Findings\FindingsFeedComposer;
use App\Services\Findings\FindingsFeedQuery;
use App\Services\Ownership\OwnedResourceFinder;
use Illuminate\Http\JsonResponse;

/**
 * Derived findings/highlights feed (`GET /documents/{document}/findings`,
 * `docs/API_SPEC.md` §7).
 *
 * Read-only: the feed is recomputed per request from findings, citations and the
 * shared derived-status rule. Nothing is persisted.
 */
final class FindingsController extends Controller
{
    public function __construct(
        private readonly FindingsFeedQuery $feedQuery,
        private readonly FindingsFeedComposer $composer,
        private readonly OwnedResourceFinder $finder,
    ) {}

    public function index(ListFindingsRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $paginator = $this->feedQuery->paginate(
            $model,
            $request->type(),
            $request->severity(),
            $request->perPage(),
        );

        return ApiResponse::collection(
            $this->composer->compose($paginator),
            FindingHighlightData::class,
        );
    }
}
