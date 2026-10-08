<?php

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Exceptions\ExtractionFailedException;
use App\Exceptions\InferenceUnavailableException;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisFailureHandler;
use Illuminate\Support\Facades\Log;
use Tests\Support\DocumentTree;

beforeEach(function () {
    $this->handler = app(AnalysisFailureHandler::class);
});

it('maps an inference outage to the safe service message', function () {
    $document = DocumentTree::create()->document;

    $this->handler->handle($document, InferenceUnavailableException::serviceUnavailable(), AnalysisStep::Extracting);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Layanan analisis tidak tersedia. Coba lagi nanti.');
});

it('maps an extraction failure to the safe unprocessable-document message', function () {
    $document = DocumentTree::create()->document;

    $this->handler->handle($document, ExtractionFailedException::invalidPdf(), AnalysisStep::Persisting);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.');
});

it('maps an unexpected exception to the generic safe message', function () {
    $document = DocumentTree::create()->document;

    $this->handler->handle($document, new RuntimeException('internal detail'), AnalysisStep::Scoring);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Failed)
        ->analysis_error->toBe('Analisis dokumen gagal. Silakan coba lagi.');
});

it('never overwrites a completed document with a late failure', function () {
    $document = ResearchedDocument::factory()->completed()->create();

    $this->handler->handle($document, new RuntimeException('late'), AnalysisStep::Scoring);

    expect($document->refresh())
        ->status->toBe(DocumentStatus::Completed)
        ->analysis_error->toBeNull();
});

it('does not throw when the document was deleted concurrently', function () {
    $document = DocumentTree::create()->document;
    $id = $document->getKey();
    $document->delete();

    $this->handler->handle($document, new RuntimeException('gone'), AnalysisStep::Extracting);

    expect(ResearchedDocument::query()->whereKey($id)->exists())->toBeFalse();
});

it('logs the raw exception with document, step and correlation id', function () {
    Log::spy();

    $document = DocumentTree::create()->document;

    $this->handler->handle($document, new RuntimeException('boom'), AnalysisStep::CrossrefValidation);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($document): bool {
            return $message === 'Document analysis failed.'
                && $context['document_id'] === $document->getKey()
                && $context['step'] === AnalysisStep::CrossrefValidation->value
                && is_string($context['correlation_id'])
                && $context['correlation_id'] !== ''
                && $context['exception'] instanceof RuntimeException;
        });
});

it('keeps the safe inference message identical to the exception message', function () {
    $exception = InferenceUnavailableException::serviceUnavailable();

    expect($this->handler->safeMessage($exception))->toBe($exception->getMessage());
});
