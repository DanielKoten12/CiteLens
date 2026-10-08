<?php

namespace App\Services\Document;

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;

/**
 * The single writer of the `researched_documents` analysis-lifecycle columns
 * (`status`, `analysis_progress`, `analysis_step`, `analysis_error`,
 * `analysis_started_at`, `analysis_completed_at`).
 *
 * Centralising these writes here keeps the state invariants (monotonic progress,
 * terminal states never re-open, safe failure messages) in exactly one place.
 * The retry endpoint, the `AnalyzeDocumentJob` failure hook and — from Phase 03 —
 * the analysis pipeline all go through this service.
 */
final class DocumentAnalysisStateService
{
    /**
     * Mark a queued/pending document as actively processing.
     *
     * `analysis_started_at` is stamped once and never moved by a repeated call,
     * so a resumed run keeps its original start time.
     */
    public function start(ResearchedDocument $document): void
    {
        $attributes = ['status' => DocumentStatus::Processing];

        if ($document->analysis_started_at === null) {
            $attributes['analysis_started_at'] = now();
        }

        $document->update($attributes);
    }

    /**
     * Move the document to a pipeline step with a monotonic progress value.
     *
     * Progress is clamped to `0..100` and never decreases. A document in a
     * terminal state is left untouched.
     */
    public function advance(ResearchedDocument $document, AnalysisStep $step, int $progress): void
    {
        if ($document->status->isTerminal()) {
            return;
        }

        $clamped = max(0, min(100, $progress));
        $monotonic = max($document->analysis_progress, $clamped);

        $document->update([
            'analysis_step' => $step,
            'analysis_progress' => $monotonic,
        ]);
    }

    /**
     * Mark the pipeline as successfully finished.
     *
     * `completed` means the pipeline ran to the end — findings are not failures.
     */
    public function complete(ResearchedDocument $document): void
    {
        $document->update([
            'status' => DocumentStatus::Completed,
            'analysis_progress' => 100,
            'analysis_step' => AnalysisStep::Completed,
            'analysis_error' => null,
            'analysis_completed_at' => now(),
        ]);
    }

    /**
     * Reset a document so a fresh run starts from a clean, queued state.
     *
     * The sender is responsible for removing the previous run's derived rows
     * ({@see DocumentAnalysisResetService}) in the same transaction.
     */
    public function resetToQueued(ResearchedDocument $document): void
    {
        $document->update([
            'status' => DocumentStatus::Pending,
            'analysis_progress' => 0,
            'analysis_step' => AnalysisStep::Queued,
            'analysis_error' => null,
            'analysis_started_at' => null,
            'analysis_completed_at' => null,
        ]);
    }

    /**
     * Transition a non-terminal document to `failed` with a safe, user-facing message.
     *
     * A missing (deleted mid-run) or already terminal document is a no-op, so a
     * late/duplicate failure can never overwrite a finished run. The raw exception
     * must be logged by the caller; only the safe message is persisted
     * (`docs/SECURITY.md` §3).
     */
    public function fail(string $documentId, string $safeMessage): void
    {
        $document = ResearchedDocument::query()->find($documentId);

        if ($document === null || $document->status->isTerminal()) {
            return;
        }

        $document->update([
            'status' => DocumentStatus::Failed,
            'analysis_error' => $safeMessage,
        ]);
    }
}
