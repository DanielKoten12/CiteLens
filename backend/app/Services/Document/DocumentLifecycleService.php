<?php

namespace App\Services\Document;

use App\Exceptions\StateConflictException;
use App\Jobs\AnalyzeDocumentJob;
use App\Models\ResearchedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Document lifecycle actions that are not plain reads (`docs/API_SPEC.md` §4).
 *
 * Retry removes the previous run's derived rows and re-queues the analysis in a
 * single transaction, then dispatches the job after commit so a worker can never
 * observe a half-reset document.
 */
final class DocumentLifecycleService
{
    public function __construct(
        private readonly DocumentAnalysisResetService $resetService,
        private readonly DocumentAnalysisStateService $stateService,
    ) {}

    /**
     * Re-run the pipeline for a `failed` document.
     *
     * @throws StateConflictException when the document is not `failed`.
     */
    public function retry(ResearchedDocument $document): ResearchedDocument
    {
        if (! $document->status->allowsRetry()) {
            throw StateConflictException::documentRetryNotAllowed();
        }

        DB::transaction(function () use ($document): void {
            $this->resetService->reset($document);
            $this->stateService->resetToQueued($document);
        });

        AnalyzeDocumentJob::dispatch($document->getKey())->afterCommit();

        return $document->refresh();
    }
}
