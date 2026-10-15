<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Exceptions\ReportGenerationFailedException;
use App\Models\GeneratedDocumentReport;
use App\Services\Document\DocumentFileManager;
use App\Services\Reports\Contracts\ReportRenderer;
use App\Services\Reports\ReportDataBuilder;
use App\Services\Reports\ReportFailureHandler;
use App\Services\Reports\ReportStateService;
use App\Services\Reports\ReportTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates one report PDF asynchronously (`docs/API_SPEC.md` §8).
 *
 * The job is the orchestrator only: data comes from {@see ReportDataBuilder},
 * HTML from {@see ReportTemplateRenderer}, PDF bytes from
 * {@see ReportRenderer}, storage from {@see DocumentFileManager} and every state
 * write goes through {@see ReportStateService}. The payload is the report id, so
 * a report deleted while queued can never break deserialization.
 */
final class GenerateDocumentReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A single deterministic run; regenerating a report is a new `POST`, not a
     * queue-level retry (D-06-07).
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $reportId,
    ) {
        $this->timeout = (int) config('reports.timeout');
        $this->onQueue((string) config('reports.queue'));
    }

    public function handle(
        ReportDataBuilder $data,
        ReportTemplateRenderer $templates,
        ReportRenderer $renderer,
        DocumentFileManager $files,
        ReportStateService $state,
        ReportFailureHandler $failures,
    ): void {
        $report = GeneratedDocumentReport::query()->find($this->reportId);

        // Deleted while queued (document cascade) → abort quietly.
        if ($report === null || $report->status->isTerminal()) {
            return;
        }

        $document = $report->researchedDocument()->first();

        // Defensive: reports only exist for completed documents.
        if ($document === null || $document->status !== DocumentStatus::Completed) {
            $failures->handle($report, ReportGenerationFailedException::documentNotCompleted());

            return;
        }

        if (! $state->markProcessing($report)) {
            return;
        }

        $file = null;

        try {
            $payload = $data->build($document);
            $pdf = $renderer->render($templates->render($payload));
            $file = $files->storePdf(
                $report,
                $pdf,
                "laporan-{$report->getKey()}.pdf",
                (string) config('reports.disk'),
            );

            if (! $state->markCompleted($report, $file)) {
                // The report was deleted while the PDF was produced.
                $files->delete($file);
            }
        } catch (Throwable $exception) {
            if ($file !== null) {
                $files->delete($file);
            }

            $failures->handle($report, $exception);
        }
    }

    /**
     * A single concurrent run per report; a duplicate that cannot acquire the
     * lock is dropped (`dontRelease()`). The lock outlives the job timeout so a
     * killed worker cannot wedge a report.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("report-generation:{$this->reportId}"))
                ->expireAfter($this->timeout + (int) config('reports.lock_expiry_buffer', 60))
                ->dontRelease(),
        ];
    }

    /**
     * Worker-level safety net (timeout, kill). The in-job handler covers step
     * failures; this guarantees no report is left stuck in `processing`.
     */
    public function failed(?Throwable $exception): void
    {
        app(ReportStateService::class)->markFailed(
            $this->reportId,
            ReportStateService::SAFE_FAILURE_MESSAGE,
        );
    }
}
