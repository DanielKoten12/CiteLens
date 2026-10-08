<?php

use App\Exceptions\ExtractionFailedException;
use App\Exceptions\InferenceClientException;
use App\Exceptions\InferenceUnavailableException;
use App\Models\File;
use App\Services\Inference\InferenceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->client = app(InferenceClient::class);
    $this->baseUrl = rtrim((string) config('services.inference.base_url'), '/');
});

function storedPdf(string $filename = 'laporan.pdf'): File
{
    Storage::fake('local');
    Storage::disk('local')->put('documents/test/'.$filename, '%PDF-1.4 fake');

    return File::factory()->create([
        'filename' => $filename,
        'path' => 'documents/test/'.$filename,
        'mime_type' => 'application/pdf',
    ]);
}

it('maps the health payload', function () {
    Http::fake([$this->baseUrl.'/health' => Http::response(Fixtures::json('inference/health'))]);

    $health = $this->client->health();

    expect($health->isHealthy())->toBeTrue()
        ->and($health->grobid)->toBe('up')
        ->and($health->sbert)->toBe('up');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === $this->baseUrl.'/health');
});

it('maps an extraction response to DTOs', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(Fixtures::json('inference/extract'))]);

    $result = $this->client->extract(storedPdf());

    expect($result->references)->toHaveCount(3)
        ->and($result->citations)->toHaveCount(3)
        ->and($result->references[0]->title)->toBe('Deep learning')
        ->and($result->citations[0]->citationText)->toBe('(LeCun et al., 2015)');
});

it('uploads the stored PDF as a multipart file', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(Fixtures::json('inference/extract'))]);

    $this->client->extract(storedPdf('laporan.pdf'));

    Http::assertSent(function (Request $request): bool {
        $file = collect($request->data())->firstWhere('name', 'file');

        return $request->method() === 'POST'
            && $request->url() === $this->baseUrl.'/v1/extract'
            && $request->hasFile('file', null, 'laporan.pdf')
            && ($file['headers']['Content-Type'] ?? null) === 'application/pdf';
    });
});

it('accepts an empty extraction payload', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(Fixtures::json('inference/extract-empty'))]);

    expect($this->client->extract(storedPdf())->isEmpty())->toBeTrue();
});

it('batches embeddings by the configured size and preserves order', function () {
    config()->set('services.inference.embedding_batch_size', 32);

    Http::fake([
        $this->baseUrl.'/v1/embeddings' => function (Request $request) {
            $texts = (array) ($request->data()['texts'] ?? []);

            return Http::response([
                'data' => [
                    'model' => 'sbert',
                    'dimensions' => 3,
                    'embeddings' => array_map(
                        static fn (string $text): array => [(float) strlen($text), 0.5, 1.0],
                        $texts,
                    ),
                ],
            ]);
        },
    ]);

    $texts = array_map(static fn (int $i): string => "text-{$i}", range(0, 69));

    $result = $this->client->embeddings($texts);

    expect($result->count())->toBe(70)
        ->and($result->dimensions)->toBe(3);

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(3);

    $sizes = array_map(
        static fn (array $pair): int => count($pair[0]->data()['texts']),
        $recorded->all(),
    );

    expect($sizes)->toBe([32, 32, 6])
        ->and($result->vectors()[0][0])->toEqual(6.0);
});

it('returns an empty embedding result without a request for no texts', function () {
    Http::fake();

    $result = $this->client->embeddings([]);

    expect($result->count())->toBe(0);

    Http::assertNothingSent();
});

it('rejects empty embedding texts locally', function () {
    Http::fake();

    expect(fn () => $this->client->embeddings(['  ']))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('treats a 5xx from extract as service unavailability', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(['error' => ['code' => 'X']], 503)]);

    expect(fn () => $this->client->extract(storedPdf()))
        ->toThrow(InferenceUnavailableException::class, 'Layanan analisis tidak tersedia. Coba lagi nanti.');
});

it('treats a connection error as service unavailability', function () {
    Http::fake([$this->baseUrl.'/*' => fn () => throw new ConnectionException('refused')]);

    $extract = fn () => $this->client->extract(storedPdf());
    $health = fn () => $this->client->health();

    expect($extract)->toThrow(InferenceUnavailableException::class)
        ->and($health)->toThrow(InferenceUnavailableException::class);
});

it('treats a 422 from extract as a fatal PDF failure', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(Fixtures::json('inference/error-extraction'), 422)]);

    expect(fn () => $this->client->extract(storedPdf()))
        ->toThrow(ExtractionFailedException::class);
});

it('treats a malformed extract body as a fatal extraction failure', function () {
    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(['unexpected' => true])]);

    expect(fn () => $this->client->extract(storedPdf()))
        ->toThrow(ExtractionFailedException::class);
});

it('treats a 4xx from embeddings as a contract bug, not a degradation', function () {
    Http::fake([$this->baseUrl.'/v1/embeddings' => Http::response(['error' => ['code' => 'INFERENCE_FAILED']], 422)]);

    expect(fn () => $this->client->embeddings(['title']))
        ->toThrow(InferenceClientException::class);
});

it('treats mismatched embedding dimensions as a contract bug', function () {
    Http::fake([
        $this->baseUrl.'/v1/embeddings' => Http::sequence()
            ->push(['data' => ['model' => 'sbert', 'dimensions' => 3, 'embeddings' => [[0.1, 0.2, 0.3]]]])
            ->push(['data' => ['model' => 'sbert', 'dimensions' => 2, 'embeddings' => [[0.1, 0.2]]]]),
    ]);

    config()->set('services.inference.embedding_batch_size', 1);

    expect(fn () => $this->client->embeddings(['first', 'second']))
        ->toThrow(InferenceClientException::class);
});

it('never logs the raw inference response on failure', function () {
    Log::spy();

    Http::fake([$this->baseUrl.'/v1/extract' => Http::response(Fixtures::json('inference/error-extraction'), 422)]);

    expect(fn () => $this->client->extract(storedPdf()))
        ->toThrow(ExtractionFailedException::class);

    Log::shouldNotHaveReceived('error');
});
