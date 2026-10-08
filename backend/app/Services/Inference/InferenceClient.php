<?php

namespace App\Services\Inference;

use App\Data\Inference\EmbeddingResultData;
use App\Data\Inference\ExtractionResultData;
use App\Data\Inference\HealthData;
use App\Exceptions\ExtractionFailedException;
use App\Exceptions\InferenceClientException;
use App\Exceptions\InferenceUnavailableException;
use App\Models\File;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Transport client for the internal FastAPI inference service
 * (`docs/API_SPEC.md` §9). Private network only; never exposed through a route
 * and never called from the frontend.
 *
 * The client owns transport concerns only: it maps the wire contract to DTOs and
 * raises typed exceptions. It never makes a reference verdict — scoring lives in
 * Laravel and verdicts in Phase 04.
 */
final class InferenceClient
{
    /**
     * Liveness check. Only used by the opt-in upload preflight (OQ-01, disabled
     * by default); no pipeline step calls it in this phase.
     *
     * @throws InferenceUnavailableException when the service is unreachable
     */
    public function health(): HealthData
    {
        $response = $this->get('/health');

        $this->assertAvailable($response);

        return HealthData::from($response->json() ?? []);
    }

    /**
     * Extract references, citations and coordinates from a stored PDF.
     *
     * @throws ExtractionFailedException when the PDF is unreadable or unparseable, or the payload is malformed
     * @throws InferenceUnavailableException when the service is unreachable
     */
    public function extract(File $file): ExtractionResultData
    {
        $stream = $this->openStream($file);

        try {
            $response = $this->pending()
                ->attach('file', $stream, $file->filename, ['Content-Type' => 'application/pdf'])
                ->post('/v1/extract');
        } catch (ConnectionException $exception) {
            throw InferenceUnavailableException::serviceUnavailable($exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->successful()) {
            $data = $response->json('data');

            if (! is_array($data)) {
                throw ExtractionFailedException::malformedPayload();
            }

            try {
                return ExtractionResultData::from($data);
            } catch (Throwable $exception) {
                throw ExtractionFailedException::malformedPayload($exception);
            }
        }

        // 5xx (and 429/408, which are transient service problems) → infeasible now.
        if ($response->serverError() || $response->status() === 429) {
            throw InferenceUnavailableException::serviceUnavailable();
        }

        // Any other 4xx (including the documented 422) → the PDF cannot be processed.
        throw ExtractionFailedException::invalidPdf();
    }

    /**
     * Embed every text in one logical call, batching by
     * `services.inference.embedding_batch_size` and merging results in input order.
     *
     * @param  list<string>  $texts  non-empty strings, one per item
     *
     * @throws InvalidArgumentException when a text is empty or not a string
     * @throws InferenceUnavailableException when the model/service is unreachable
     * @throws InferenceClientException when a non-transient response violates the contract
     */
    public function embeddings(array $texts): EmbeddingResultData
    {
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException('Every embedding text must be a non-empty string.');
            }
        }

        if ($texts === []) {
            return new EmbeddingResultData(model: 'sbert', dimensions: 0);
        }

        $batchSize = max(1, (int) config('services.inference.embedding_batch_size', 32));

        $model = null;
        $dimensions = null;
        $vectors = [];

        foreach (array_chunk($texts, $batchSize) as $chunk) {
            $batch = $this->embeddingBatch($chunk);

            if ($model === null) {
                $model = $batch->model;
                $dimensions = $batch->dimensions;
            } elseif ($batch->dimensions !== $dimensions) {
                throw InferenceClientException::malformedPayload();
            }

            foreach ($batch->vectors() as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            throw InferenceClientException::malformedPayload();
        }

        return new EmbeddingResultData(
            model: $model ?? 'sbert',
            dimensions: $dimensions ?? 0,
            embeddings: $vectors,
        );
    }

    /**
     * @param  list<string>  $texts
     */
    private function embeddingBatch(array $texts): EmbeddingResultData
    {
        try {
            $response = $this->pending()->post('/v1/embeddings', [
                'texts' => $texts,
                'model' => 'sbert',
            ]);
        } catch (ConnectionException $exception) {
            throw InferenceUnavailableException::serviceUnavailable($exception);
        }

        $this->assertAvailable($response);

        if (! $response->successful()) {
            throw InferenceClientException::unexpectedResponse($response->status());
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw InferenceClientException::malformedPayload();
        }

        try {
            return EmbeddingResultData::from($data);
        } catch (Throwable $exception) {
            throw InferenceClientException::malformedPayload($exception);
        }
    }

    private function get(string $path): Response
    {
        try {
            return $this->pending()->get($path);
        } catch (ConnectionException $exception) {
            throw InferenceUnavailableException::serviceUnavailable($exception);
        }
    }

    /**
     * Map transport-level and 5xx failures to {@see InferenceUnavailableException}.
     */
    private function assertAvailable(Response $response): void
    {
        if ($response->serverError() || $response->status() === 429) {
            throw InferenceUnavailableException::serviceUnavailable();
        }
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.inference.base_url'), '/'))
            ->timeout((int) config('services.inference.timeout'))
            ->connectTimeout((int) config('services.inference.connect_timeout'))
            ->acceptJson();
    }

    /**
     * @return resource
     */
    private function openStream(File $file)
    {
        try {
            $stream = Storage::disk((string) config('filesystems.default'))->readStream($file->path);
        } catch (Throwable $exception) {
            throw ExtractionFailedException::fileUnreadable();
        }

        if (! is_resource($stream)) {
            throw ExtractionFailedException::fileUnreadable();
        }

        return $stream;
    }
}
