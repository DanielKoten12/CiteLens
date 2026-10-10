<?php

namespace App\Services\Reports;

use App\Models\GeneratedDocumentReport;
use App\Services\Document\DocumentDeletionService;
use App\Services\Document\DocumentFileManager;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes one generated report and its stored file (`docs/API_SPEC.md` §8).
 *
 * Rows are deleted inside a transaction; the stored objects are removed after
 * the commit, best-effort (failures are logged, never fatal) — the same order as
 * {@see DocumentDeletionService}.
 */
final class ReportDeletionService
{
    public function __construct(
        private readonly DocumentFileManager $fileManager,
    ) {}

    /**
     * Delete one report: its `files` rows, the report row and the stored PDF.
     */
    public function delete(GeneratedDocumentReport $report): void
    {
        $objects = DB::transaction(function () use ($report): array {
            $objects = $this->fileManager->detachForReport($report);

            $report->delete();

            return $objects;
        });

        $this->fileManager->deleteStoredObjects($objects);
    }
}
