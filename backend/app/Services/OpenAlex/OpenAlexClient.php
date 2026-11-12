<?php

namespace App\Services\OpenAlex;

use App\Contracts\Metadata\BibliographicSource;
use App\Contracts\Metadata\DoiResolver;
use App\Enums\MetadataSource;
use App\Exceptions\OpenAlexUnavailableException;
use App\Services\Metadata\ReferenceQuery;
use App\Services\Metadata\Support\RequestThrottle;
use App\Services\Metadata\Support\SearchCache;
use App\Services\Metadata\WorkMetadata;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport client for the OpenAlex Works API.
 *
 * Outbound from queue workers only; never called by the frontend and never
 * exposed through a route. Searches use `title.search`; DOI lookups are free
 * single-entity calls. The client owns transport concerns: bearer header,
 * polite-pool `mailto`, timeouts, bounded retries, `429`/`Retry-After`
 * handling, shared throttle/cache and wire → value-object mapping.
 *
 * Key-gated (D-09-17): keyless access only has a tiny shared daily budget, so
 * the source is disabled without a key. The key travels in the `Authorization`
 * header, never in the query string.
 */
final class OpenAlexClient implements BibliographicSource, DoiResolver
{
    private const SEARCH_CACHE_PREFIX = 'openalex:search:';

    private const DOI_CACHE_PREFIX = 'openalex:doi:';

    private const THROTTLE_LOCK = 'openalex:throttle';

    public function __construct(
        private readonly OpenAlexQueryBuilder $queryBuilder,
        private readonly OpenAlexResultMapper $mapper,
        private readonly SearchCache $cache,
        private readonly RequestThrottle $throttle,
    ) {}

    public function source(): MetadataSource
    {
        return MetadataSource::OpenAlex;
    }

    public function enabled(): bool
    {
        return (bool) config('services.openalex.enabled', false)
            && trim((string) config('services.openalex.api_key')) !== '';
    }

    /**
     * @return list<WorkMetadata>
     *
     * @throws OpenAlexUnavailableException
     */
    public function search(ReferenceQuery $query): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $params = $this->queryBuilder->searchParams($query);

        $results = $this->cache->remember(
            self::SEARCH_CACHE_PREFIX.sha1((string) json_encode($params)),
            $this->ttl(),
            $this->negativeTtl(),
            fn (): array => $this->fetchSearch($params),
        );

        return $this->mapper->mapSearch(['results' => $results]);
    }

    /**
     * Resolve a normalized DOI.
     *
     * `null` means OpenAlex returned `404` (a definitive miss for this
     * resolver). An outage throws and the DOI chain applies the failure policy.
     *
     * @throws OpenAlexUnavailableException
     */
    public function resolve(string $normalizedDoi): ?WorkMetadata
    {
        if (! $this->enabled()) {
            return null;
        }

        $results = $this->cache->remember(
            self::DOI_CACHE_PREFIX.$normalizedDoi,
            $this->ttl(),
            $this->negativeTtl(),
            fn (): array => $this->fetchWork($normalizedDoi),
        );

        return $results === [] ? null : $this->mapper->mapWork($results[0]);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return list<array<string, mixed>>
     *
     * @throws OpenAlexUnavailableException
     */
    private function fetchSearch(array $params): array
    {
        $payload = $this->request('works', $params);

        if ($payload === null) {
            return [];
        }

        $results = $payload['results'] ?? null;

        if (! is_array($results)) {
            throw OpenAlexUnavailableException::unexpectedFormat('missing results array');
        }

        return array_values(array_filter($results, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws OpenAlexUnavailableException
     */
    private function fetchWork(string $normalizedDoi): array
    {
        $work = $this->request($this->queryBuilder->doiPath($normalizedDoi), $this->queryBuilder->doiParams());

        if ($work === null) {
            return [];
        }

        $id = $work['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw OpenAlexUnavailableException::unexpectedFormat('missing work id');
        }

        return [$work];
    }

    /**
     * Execute one GET with bounded retries on transient failures.
     *
     * `404` and `400` are definitive per-reference outcomes, not outages. A
     * bounded `429` retry honors `Retry-After`; a longer wait (especially an
     * exhausted daily budget) is reported as an outage so the circuit breaker
     * prevents repeated budget calls.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null decoded JSON, or `null` on a definitive miss
     *
     * @throws OpenAlexUnavailableException
     */
    private function request(string $path, array $query = []): ?array
    {
        $attempts = max(1, (int) config('services.openalex.retries', 1) + 1);
        $rateLimitRetried = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                /** @var Response $response */
                $response = $this->throttle->run(
                    lockName: self::THROTTLE_LOCK,
                    minIntervalMs: $this->minIntervalMs(),
                    callback: fn (): Response => $this->pending()->get($path, $query),
                    onLockTimeout: static fn (LockTimeoutException $exception): OpenAlexUnavailableException => OpenAlexUnavailableException::serviceUnavailable($exception),
                    lockSeconds: max(10, 2 * $this->timeout() * ($attempts + 1)),
                    waitSeconds: max(2, $this->timeout() + 2),
                );
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw OpenAlexUnavailableException::serviceUnavailable($exception);
            }

            $status = $response->status();

            if ($status === 404) {
                return null;
            }

            if ($status === 400) {
                Log::warning('OpenAlex rejected a per-reference query.', [
                    'source' => MetadataSource::OpenAlex->value,
                    'status' => $status,
                ]);

                return null;
            }

            if ($status === 401 || $status === 403) {
                Log::error('OpenAlex rejected the configured credentials.', [
                    'source' => MetadataSource::OpenAlex->value,
                    'status' => $status,
                ]);

                throw OpenAlexUnavailableException::unauthorized();
            }

            if ($status === 429) {
                $retryAfter = $this->retryAfterSeconds($response);
                $cap = max(0, (int) config('services.openalex.max_retry_after_seconds', 5));

                if (! $rateLimitRetried && $retryAfter !== null && $retryAfter <= $cap && $attempt < $attempts) {
                    $rateLimitRetried = true;
                    $this->sleepSeconds($retryAfter);

                    continue;
                }

                throw OpenAlexUnavailableException::rateLimited($retryAfter);
            }

            if ($response->serverError()) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw OpenAlexUnavailableException::unexpectedResponse($status);
            }

            if ($response->failed()) {
                throw OpenAlexUnavailableException::unexpectedResponse($status);
            }

            return $this->decodeJson($response);
        }

        throw OpenAlexUnavailableException::serviceUnavailable();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OpenAlexUnavailableException
     */
    private function decodeJson(Response $response): array
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($contentType, 'json')) {
            throw OpenAlexUnavailableException::unexpectedFormat('content type '.$contentType);
        }

        $body = $response->body();

        if (strlen($body) > $this->maxResponseBytes()) {
            throw OpenAlexUnavailableException::unexpectedFormat('response too large');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw OpenAlexUnavailableException::unexpectedFormat('invalid JSON');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.openalex.base_url'), '/'))
            ->timeout($this->timeout())
            ->connectTimeout(max(1, (int) config('services.openalex.connect_timeout', 5)))
            ->acceptJson()
            ->withUserAgent($this->userAgent())
            ->withToken((string) config('services.openalex.api_key'));
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function sleepSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            usleep($seconds * 1_000_000);
        }
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('services.openalex.retry_backoff_ms', 300));

        if ($base > 0) {
            usleep((int) ($base * (2 ** max(0, $attempt - 1)) * 1000));
        }
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('services.openalex.user_agent'));

        if ($configured !== '') {
            return $configured;
        }

        $mailto = trim((string) config('services.openalex.mailto'));

        return $mailto === ''
            ? 'DafpusCek/1.0'
            : 'DafpusCek/1.0 (mailto:'.$mailto.')';
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.openalex.timeout', 10));
    }

    private function minIntervalMs(): int
    {
        return max(0, (int) config('services.openalex.min_interval_ms', 200));
    }

    private function ttl(): int
    {
        return max(0, (int) config('services.openalex.cache_ttl', 2592000));
    }

    private function negativeTtl(): int
    {
        return max(0, (int) config('services.openalex.negative_cache_ttl', 86400));
    }

    private function maxResponseBytes(): int
    {
        return max(1, (int) config('services.openalex.max_response_bytes', 2097152));
    }
}
