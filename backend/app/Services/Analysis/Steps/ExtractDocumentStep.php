<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Exceptions\ExtractionFailedException;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Inference\InferenceClient;

/**
 * Calls the internal extraction service for the document's stored PDF and puts
 * the result on the context for {@see PersistExtractionStep}.
 *
 * The step performs no persistence: all durable writes happen in the persistence
 * step, which keeps the two canonical steps (`extracting`, `persisting`) honest.
 */
final class ExtractDocumentStep implements PipelineStep
{
    public function __construct(
        private readonly InferenceClient $client,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::Extracting;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $file = $document->file()->first() ?? throw ExtractionFailedException::fileMissing();

        $context->setExtraction($this->client->extract($file));
    }
}
