<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use App\Services\Analysis\Contracts\RunsDocumentAnalysis;
use App\Services\Document\DocumentAnalysisStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Queues the asynchronous analysis of an uploaded document (`docs/API_SPEC.md` §10).
 *
 * This class is the queue transport only: it guards the document's state and
 * delegates the actual pipeline to {@see RunsDocumentAnalysis} (implemented in
 * Phase 03). The payload is the document id so a document deleted while queued
 * can never break deserialization.
 */
final class AnalyzeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A single deterministic run; retrying analysis is a domain action
     * (`POST /documents/{document}/retry`), not a queue-level retry.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $documentId,
    ) {
        $this->timeout = (int) config('analysis.timeout');
        $this->onQueue((string) config('analysis.queue'));
    }

    /**
     * Resolve the document, verify it may be analysed, then run the pipeline.
     */
    public function handle(RunsDocumentAnalysis $pipeline): void
    {
        $document = ResearchedDocument::query()->find($this->documentId);

        // Deleted while queued: abort quietly, no exception noise.
        if ($document === null) {
            return;
        }

        // Only a fresh/queued run may start; terminal states are ignored.
        if (! in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
            return;
        }

        $pipeline->run($document);
    }

    /**
     * A single concurrent run per document is enough; a duplicate that cannot
     * acquire the lock is dropped (`dontRelease()`), which is safe because the
     * status guard plus idempotent steps make a second run redundant. The lock
     * outlives the job timeout so a killed worker cannot wedge a document.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("document-analysis:{$this->documentId}"))
                ->expireAfter($this->timeout + (int) config('analysis.lock_expiry_buffer', 60))
                ->dontRelease(),
        ];
    }

    /**
     * Worker-level safety net (timeout, kill, unresolvable pipeline). Phase 03's
     * in-pipeline handler covers step failures; this guarantees no document is
     * left stuck in `processing`.
     */
    public function failed(?Throwable $exception): void
    {
        app(DocumentAnalysisStateService::class)->fail(
            $this->documentId,
            'Analisis dokumen gagal. Silakan coba lagi.',
        );
    }
}
