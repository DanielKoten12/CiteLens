<?php

namespace App\Services\Reports;

use App\Exceptions\FileStorageException;
use App\Exceptions\ReportRenderingException;
use App\Models\GeneratedDocumentReport;
use App\Services\Analysis\AnalysisFailureHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps a fatal report-generation exception to a safe `error` and the `failed`
 * transition, mirroring {@see AnalysisFailureHandler}.
 *
 * The raw exception goes to the log with report/document ids only; the persisted
 * `error` never contains a stack trace, URL or document text
 * (`docs/SECURITY.md` §10).
 */
final class ReportFailureHandler
{
    public function __construct(
        private readonly ReportStateService $state,
    ) {}

    /**
     * Log the failure and mark the report failed (no-op for a missing or already
     * terminal report).
     */
    public function handle(GeneratedDocumentReport $report, Throwable $exception): void
    {
        Log::error('Document report generation failed.', [
            'report_id' => $report->getKey(),
            'document_id' => $report->researched_document_id,
            'exception' => $exception,
        ]);

        $this->state->markFailed($report->getKey(), $this->safeMessage($exception));
    }

    /**
     * The safe `error` for an exception.
     */
    public function safeMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ReportRenderingException => 'Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.',
            $exception instanceof FileStorageException => 'Laporan gagal disimpan. Silakan coba lagi.',
            default => ReportStateService::SAFE_FAILURE_MESSAGE,
        };
    }
}
