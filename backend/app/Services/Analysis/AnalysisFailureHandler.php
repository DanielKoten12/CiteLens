<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisStep;
use App\Exceptions\ExtractionFailedException;
use App\Exceptions\InferenceUnavailableException;
use App\Models\ResearchedDocument;
use App\Services\Document\DocumentAnalysisStateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Maps a fatal pipeline exception to a safe, user-facing `analysis_error` and
 * the `failed` transition.
 *
 * The raw exception (with its trace) is written to the log with the document id,
 * the step and a correlation id; only the safe message is persisted
 * (`docs/SECURITY.md` §3). The mapping is the single source of the safe strings —
 * add a branch here when a new external dependency gets a distinct message.
 */
final class AnalysisFailureHandler
{
    public function __construct(
        private readonly DocumentAnalysisStateService $state,
    ) {}

    /**
     * Log the failure and transition the document (no-op for a missing or already
     * terminal document, so a late failure never overwrites a finished run).
     */
    public function handle(ResearchedDocument $document, Throwable $exception, AnalysisStep $step): void
    {
        $correlationId = (string) Str::uuid();

        Log::error('Document analysis failed.', [
            'document_id' => $document->getKey(),
            'step' => $step->value,
            'correlation_id' => $correlationId,
            'exception' => $exception,
        ]);

        $this->state->fail($document->getKey(), $this->safeMessage($exception));
    }

    /**
     * The safe `analysis_error` for an exception.
     */
    public function safeMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ExtractionFailedException => 'Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.',
            $exception instanceof InferenceUnavailableException => 'Layanan analisis tidak tersedia. Coba lagi nanti.',
            default => 'Analisis dokumen gagal. Silakan coba lagi.',
        };
    }
}
