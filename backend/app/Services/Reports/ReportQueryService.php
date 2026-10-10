<?php

namespace App\Services\Reports;

use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read queries for a document's reports
 * (`GET /documents/{document}/reports`, `docs/API_SPEC.md` §8).
 *
 * Default order is `created_at` descending (spec §2.7) with an `id` tiebreaker
 * so pagination is deterministic; the file is eager-loaded so `download_url`
 * resolution cannot trigger an N+1.
 */
final class ReportQueryService
{
    /**
     * @return LengthAwarePaginator<int, GeneratedDocumentReport>
     */
    public function paginate(ResearchedDocument $document, int $perPage = 15): LengthAwarePaginator
    {
        return $document->reports()
            ->with('file')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
