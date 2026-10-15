<?php

namespace App\Services\Analysis\Steps;

use App\Enums\AnalysisStep;
use App\Exceptions\InferenceClientException;
use App\Exceptions\InferenceUnavailableException;
use App\Models\ResearchedDocument;
use App\Services\Analysis\AnalysisContext;
use App\Services\Analysis\Contracts\PipelineStep;
use App\Services\Analysis\EmbeddingIndex;
use App\Services\Inference\InferenceClient;
use App\Services\Scoring\ScoringConfig;
use Illuminate\Support\Facades\Log;

/**
 * `embedding` — embeds reference and candidate titles through the internal
 * SBERT service and stores a keyed {@see EmbeddingIndex} on the context.
 *
 * Degradation policy (OQ-04 / D-03-09): `InferenceUnavailableException`
 * (5xx/timeout/connection) degrades to string-only scoring and records the
 * degradation in the finding reason; an `InferenceClientException` (4xx or a
 * contract violation) stays fatal.
 */
final class EmbedReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly InferenceClient $client,
        private readonly ScoringConfig $config,
    ) {}

    public function step(): AnalysisStep
    {
        return AnalysisStep::Embedding;
    }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $batch = $context->verification();

        if (! $this->config->semanticEnabled()) {
            $context->setEmbeddings(EmbeddingIndex::unavailable());

            return;
        }

        $texts = [];
        $keys = [];

        foreach ($batch->verifications as $verification) {
            $referenceId = $verification->reference->id;
            $title = $verification->reference->title;

            if ($title !== null && trim($title) !== '') {
                $texts[] = $title;
                $keys[] = EmbeddingIndex::referenceKey($referenceId);
            }

            foreach ($verification->candidates as $index => $candidate) {
                $candidateTitle = $candidate->title;

                if ($candidateTitle !== null && trim($candidateTitle) !== '') {
                    $texts[] = $candidateTitle;
                    $keys[] = EmbeddingIndex::candidateKey($referenceId, $index);
                }
            }
        }

        if ($texts === []) {
            $context->setEmbeddings(EmbeddingIndex::empty());

            return;
        }

        try {
            $result = $this->client->embeddings($texts);
        } catch (InferenceUnavailableException $exception) {
            Log::warning('Embeddings unavailable; reference scoring degrades to string signals.', [
                'document_id' => $document->getKey(),
                'exception' => $exception,
            ]);

            $context->setEmbeddings(EmbeddingIndex::unavailable());

            return;
        }

        if (count($result->vectors()) !== count($texts)) {
            throw InferenceClientException::malformedPayload();
        }

        /** @var array<string, list<float>> $vectors */
        $vectors = array_combine($keys, $result->vectors());

        $context->setEmbeddings(EmbeddingIndex::fromVectors($vectors));
    }
}
