<?php

namespace App\Services\Reports;

use App\Enums\DocumentStatus;
use App\Enums\ReportStatus;
use App\Exceptions\StateConflictException;
use App\Jobs\GenerateDocumentReportJob;
use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Creates a report row and queues its generation
 * (`POST /documents/{document}/reports`, `docs/API_SPEC.md` §8).
 *
 * Reports are only generated for `completed` documents (anything else is a
 * `409 CONFLICT`) and generation is always asynchronous: this service persists
 * `pending` and dispatches the job after commit, it never renders inline.
 * Regenerating a failed report is simply another call (OQ-07).
 */
final class ReportGenerationService
{
    public function generate(ResearchedDocument $document): GeneratedDocumentReport
    {
        if ($document->status !== DocumentStatus::Completed) {
            throw StateConflictException::reportNotAllowed();
        }

        $report = DB::transaction(fn (): GeneratedDocumentReport => GeneratedDocumentReport::query()->create([
            'researched_document_id' => $document->getKey(),
            'status' => ReportStatus::Pending,
        ]));

        GenerateDocumentReportJob::dispatch($report->getKey())->afterCommit();

        return $report;
    }
}
