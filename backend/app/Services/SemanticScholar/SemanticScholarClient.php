<?php

namespace App\Services\SemanticScholar;

use App\Contracts\Metadata\BibliographicSource;
use App\Contracts\Metadata\DoiResolver;
use App\Enums\MetadataSource;
use App\Exceptions\SemanticScholarUnavailableException;
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
 * Transport client for the Semantic Scholar Graph API.
 *
 * Outbound from queue workers only; never called by the frontend and never
 * exposed through a route. The client owns transport concerns: key header,
 * polite User-Agent, timeouts, bounded retries, `429`/`Retry-After` handling,
 * shared throttle/cache and wire → value-object mapping. It never makes a
 * verification verdict.
 *
 * Key-gated (D-09-17): without an API key the source is disabled, because
 * unauthenticated requests share a heavily throttled pool and its `429`s would
 * otherwise be reported as "not indexed".
 */
final class SemanticScholarClient implements BibliographicSource, DoiResolver
{
    private const SEARCH_PATH = 'graph/v1/paper/search';

    private const SEARCH_CACHE_PREFIX = 'semantic_scholar:search:';

    private const DOI_CACHE_PREFIX = 'semantic_scholar:doi:';

    private const THROTTLE_LOCK = 'semantic_scholar:throttle';

    public function __construct(
        private readonly SemanticScholarQueryBuilder $queryBuilder,
        private readonly SemanticScholarResultMapper $mapper,
        private readonly SearchCache $cache,
        private readonly RequestThrottle $throttle,
    ) {}

    public function source(): MetadataSource
    {
        return MetadataSource::SemanticScholar;
    }

    public function enabled(): bool
    {
        return (bool) config('services.semantic_scholar.enabled', false)
            && trim((string) config('services.semantic_scholar.api_key')) !== '';
    }

    /**
     * @return list<WorkMetadata>
     *
     * @throws SemanticScholarUnavailableException
     */
    public function search(ReferenceQuery $query): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $params = $this->queryBuilder->searchParams($query);

        $papers = $this->cache->remember(
            self::SEARCH_CACHE_PREFIX.sha1((string) json_encode($params)),
            $this->ttl(),
            $this->negativeTtl(),
            fn (): array => $this->fetchSearch($params),
        );

        return $this->mapper->mapSearch(['data' => $papers]);
    }

    /**
     * Resolve a normalized DOI.
     *
     * `null` means the DOI endpoint returned `404` (a definitive miss for this
     * resolver). An outage throws and the DOI chain applies the failure policy.
     *
     * @throws SemanticScholarUnavailableException
     */
    public function resolve(string $normalizedDoi): ?WorkMetadata
    {
        if (! $this->enabled()) {
            return null;
        }

        $papers = $this->cache->remember(
            self::DOI_CACHE_PREFIX.$normalizedDoi,
            $this->ttl(),
            $this->negativeTtl(),
            fn (): array => $this->fetchPaper($normalizedDoi),
        );

        return $papers === [] ? null : $this->mapper->mapPaper($papers[0]);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return list<array<string, mixed>>
     *
     * @throws SemanticScholarUnavailableException
     */
    private function fetchSearch(array $params): array
    {
        $payload = $this->request(self::SEARCH_PATH, $params);

        if ($payload === null) {
            return [];
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw SemanticScholarUnavailableException::unexpectedFormat('missing data array');
        }

        return array_values(array_filter($data, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws SemanticScholarUnavailableException
     */
    private function fetchPaper(string $normalizedDoi): array
    {
        $paper = $this->request($this->queryBuilder->doiPath($normalizedDoi));

        if ($paper === null) {
            return [];
        }

        $paperId = $paper['paperId'] ?? null;

        if (! is_string($paperId) || trim($paperId) === '') {
            throw SemanticScholarUnavailableException::unexpectedFormat('missing paperId');
        }

        return [$paper];
    }

    /**
     * Execute one GET with bounded retries on transient failures.
     *
     * `404` and `400` are definitive per-reference outcomes (the chain keeps
     * going), not outages. A bounded `429` retry honors `Retry-After`; a longer
     * wait is reported as an outage so a queue worker never blocks for minutes.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null decoded JSON, or `null` on a definitive miss
     *
     * @throws SemanticScholarUnavailableException
     */
    private function request(string $path, array $query = []): ?array
    {
        $attempts = max(1, (int) config('services.semantic_scholar.retries', 1) + 1);
        $rateLimitRetried = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                /** @var Response $response */
                $response = $this->throttle->run(
                    lockName: self::THROTTLE_LOCK,
                    minIntervalMs: $this->minIntervalMs(),
                    callback: fn (): Response => $this->pending()->get($path, $query),
                    onLockTimeout: static fn (LockTimeoutException $exception): SemanticScholarUnavailableException => SemanticScholarUnavailableException::serviceUnavailable($exception),
                    lockSeconds: max(10, 2 * $this->timeout() * ($attempts + 1)),
                    waitSeconds: max(2, $this->timeout() + 2),
                );
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw SemanticScholarUnavailableException::serviceUnavailable($exception);
            }

            $status = $response->status();

            if ($status === 404) {
                return null;
            }

            if ($status === 400) {
                Log::warning('Semantic Scholar rejected a per-reference query.', [
                    'source' => MetadataSource::SemanticScholar->value,
                    'status' => $status,
                ]);

                return null;
            }

            if ($status === 401 || $status === 403) {
                Log::error('Semantic Scholar rejected the configured credentials.', [
                    'source' => MetadataSource::SemanticScholar->value,
                    'status' => $status,
                ]);

                throw SemanticScholarUnavailableException::unauthorized();
            }

            if ($status === 429) {
                $retryAfter = $this->retryAfterSeconds($response);
                $cap = max(0, (int) config('services.semantic_scholar.max_retry_after_seconds', 5));

                if (! $rateLimitRetried && $retryAfter !== null && $retryAfter <= $cap && $attempt < $attempts) {
                    $rateLimitRetried = true;
                    $this->sleepSeconds($retryAfter);

                    continue;
                }

                throw SemanticScholarUnavailableException::rateLimited($retryAfter);
            }

            if ($response->serverError()) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw SemanticScholarUnavailableException::unexpectedResponse($status);
            }

            if ($response->failed()) {
                throw SemanticScholarUnavailableException::unexpectedResponse($status);
            }

            return $this->decodeJson($response);
        }

        throw SemanticScholarUnavailableException::serviceUnavailable();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SemanticScholarUnavailableException
     */
    private function decodeJson(Response $response): array
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($contentType, 'json')) {
            throw SemanticScholarUnavailableException::unexpectedFormat('content type '.$contentType);
        }

        $body = $response->body();

        if (strlen($body) > $this->maxResponseBytes()) {
            throw SemanticScholarUnavailableException::unexpectedFormat('response too large');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw SemanticScholarUnavailableException::unexpectedFormat('invalid JSON');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.semantic_scholar.base_url'), '/'))
            ->timeout($this->timeout())
            ->connectTimeout(max(1, (int) config('services.semantic_scholar.connect_timeout', 5)))
            ->acceptJson()
            ->withUserAgent($this->userAgent())
            ->withHeaders(['x-api-key' => (string) config('services.semantic_scholar.api_key')]);
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
        $base = max(0, (int) config('services.semantic_scholar.retry_backoff_ms', 300));

        if ($base > 0) {
            usleep((int) ($base * (2 ** max(0, $attempt - 1)) * 1000));
        }
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('services.semantic_scholar.user_agent'));

        if ($configured !== '') {
            return $configured;
        }

        $mailto = trim((string) config('services.crossref.mailto'));

        return $mailto === ''
            ? 'DafpusCek/1.0'
            : 'DafpusCek/1.0 (+mailto:'.$mailto.')';
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.semantic_scholar.timeout', 10));
    }

    private function minIntervalMs(): int
    {
        return max(0, (int) config('services.semantic_scholar.min_interval_ms', 1100));
    }

    private function ttl(): int
    {
        return max(0, (int) config('services.semantic_scholar.cache_ttl', 2592000));
    }

    private function negativeTtl(): int
    {
        return max(0, (int) config('services.semantic_scholar.negative_cache_ttl', 86400));
    }

    private function maxResponseBytes(): int
    {
        return max(1, (int) config('services.semantic_scholar.max_response_bytes', 2097152));
    }
}
