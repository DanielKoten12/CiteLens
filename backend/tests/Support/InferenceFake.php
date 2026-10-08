<?php

namespace Tests\Support;

use App\Services\Inference\InferenceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fixture-driven `Http::fake` stubs for the internal inference service.
 *
 * OQ-13: the FastAPI service is not implemented yet and the backend never ships a
 * production stub. Tests drive the real {@see InferenceClient}
 * through its HTTP boundary with recorded payloads instead.
 */
final class InferenceFake
{
    public static function health(): void
    {
        self::stub('/health', Fixtures::json('inference/health'));
    }

    public static function extraction(): void
    {
        self::stub('/v1/extract', Fixtures::json('inference/extract'));
    }

    public static function emptyExtraction(): void
    {
        self::stub('/v1/extract', Fixtures::json('inference/extract-empty'));
    }

    public static function malformedExtraction(): void
    {
        self::stub('/v1/extract', Fixtures::json('inference/extract-malformed'));
    }

    public static function unparseablePdf(): void
    {
        self::stub('/v1/extract', Fixtures::json('inference/error-extraction'), 422);
    }

    /**
     * A well-formed embeddings response for whatever texts the request carries.
     */
    public static function embeddings(int $dimensions = 3): void
    {
        Http::fake([
            self::baseUrl().'/v1/embeddings' => function (Request $request) use ($dimensions) {
                $texts = (array) ($request->data()['texts'] ?? []);

                return Http::response([
                    'data' => [
                        'model' => 'sbert',
                        'dimensions' => $dimensions,
                        'embeddings' => array_map(
                            static fn (): array => array_fill(0, $dimensions, 0.5),
                            $texts,
                        ),
                    ],
                ]);
            },
        ]);
    }

    /**
     * Any inference endpoint responds with the documented 5xx error envelope.
     */
    public static function unavailable(): void
    {
        Http::fake([
            self::baseUrl().'/*' => Http::response([
                'error' => ['code' => 'INFERENCE_FAILED', 'message' => 'Service unavailable.'],
            ], 503),
        ]);
    }

    public static function connectionError(): void
    {
        Http::fake([
            self::baseUrl().'/*' => fn () => throw new ConnectionException('Connection refused.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function stub(string $path, array $body, int $status = 200): void
    {
        Http::fake([self::baseUrl().$path => Http::response($body, $status)]);
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.inference.base_url'), '/');
    }
}
