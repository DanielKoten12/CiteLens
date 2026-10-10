<?php

namespace App\Http\Controllers\Api;

use App\Data\Report\GeneratedDocumentReportDetailData;
use App\Data\Report\GeneratedDocumentReportSummaryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ListReportsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\GeneratedDocumentReport;
use App\Services\Files\PrivateFileUrlResolver;
use App\Services\Ownership\OwnedResourceFinder;
use App\Services\Reports\ReportDeletionService;
use App\Services\Reports\ReportGenerationService;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * On-demand report endpoints (`docs/API_SPEC.md` §8).
 *
 * Transport only: ownership is resolved by {@see OwnedResourceFinder}, the row is
 * created/queried/deleted by the report services, and the download URL is built
 * only through {@see PrivateFileUrlResolver}. No report state is ever written here.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportQueryService $queryService,
        private readonly ReportGenerationService $generationService,
        private readonly ReportDeletionService $deletionService,
        private readonly PrivateFileUrlResolver $fileUrls,
        private readonly OwnedResourceFinder $finder,
    ) {}

    /**
     * Queue a new report for a completed document.
     */
    public function store(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);
        $report = $this->generationService->generate($model);

        return ApiResponse::accepted(
            GeneratedDocumentReportSummaryData::forReport($report, null),
            'Laporan sedang dibuat.',
        );
    }

    /**
     * Paginated report history for one document.
     */
    public function index(ListReportsRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $paginator = $this->queryService->paginate($model, $request->perPage());

        $paginator->through(
            fn (GeneratedDocumentReport $report): GeneratedDocumentReportSummaryData => GeneratedDocumentReportSummaryData::forReport(
                $report,
                $this->fileUrls->resolve($report->file?->path, $report->file?->disk),
            ),
        );

        return ApiResponse::collection($paginator, GeneratedDocumentReportSummaryData::class);
    }

    /**
     * Full report detail.
     */
    public function show(Request $request, string $report): JsonResponse
    {
        $model = $this->finder->report($request->user(), $report);
        $model->load('file');

        return ApiResponse::single(GeneratedDocumentReportDetailData::forReport(
            $model,
            $this->fileUrls->resolve($model->file?->path, $model->file?->disk),
        ));
    }

    /**
     * Hard-delete one report and its stored PDF.
     */
    public function destroy(Request $request, string $report): Response
    {
        $this->deletionService->delete($this->finder->report($request->user(), $report));

        return ApiResponse::noContent();
    }
}
