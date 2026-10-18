<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\File;
use App\Models\GeneratedDocumentReport;

/**
 * The **only** writer of `generated_document_reports.status|error|file_id|generated_at`.
 *
 * The generation job and its `failed()` hook go through this service; endpoints
 * never write report state. Updates are state-guarded so a late worker or a
 * concurrent delete cannot resurrect a terminal report.
 */
final class ReportStateService
{
    /**
     * Safe message persisted for any failure without a more specific mapping.
     */
    public const string SAFE_FAILURE_MESSAGE = 'Laporan gagal dibuat. Silakan coba lagi.';

    /**
     * Atomically claim a report for processing.
     *
     * `pending` and a stale `processing` row (a job redelivered after a worker
     * kill) are both claimable; a terminal row is not.
     */
    public function markProcessing(GeneratedDocumentReport $report): bool
    {
        return GeneratedDocumentReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'status' => ReportStatus::Processing->value,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Complete the report and link its stored file.
     *
     * Returns false when the row disappeared or moved to a terminal state while
     * the PDF was being produced (the caller must then delete the orphaned file).
     */
    public function markCompleted(GeneratedDocumentReport $report, File $file): bool
    {
        return GeneratedDocumentReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'file_id' => $file->getKey(),
                'status' => ReportStatus::Completed->value,
                'error' => null,
                'generated_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Safe failure: missing and already-terminal reports are left untouched.
     */
    public function markFailed(string $reportId, string $safeMessage): void
    {
        GeneratedDocumentReport::query()
            ->whereKey($reportId)
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'status' => ReportStatus::Failed->value,
                'error' => $safeMessage,
                'generated_at' => null,
                'updated_at' => now(),
            ]);
    }
}
