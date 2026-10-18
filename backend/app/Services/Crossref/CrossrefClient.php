<?php

namespace App\Services\Crossref;

use App\Exceptions\CrossrefUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Transport client for the public Crossref REST API (`docs/API_SPEC.md` §1/§10).
 *
 * Outbound from queue workers only; never called by the frontend and never
 * exposed through a route. The client owns transport concerns: polite-pool
 * identification, timeouts, bounded retries on transient failures, DOI caching
 * and wire → value-object mapping. It never makes a verification verdict —
 * scoring lives in `App\Services\Scoring`.
 */
final class CrossrefClient
{
    private const DOI_CACHE_PREFIX = 'crossref:doi:';

    public function __construct(
        private readonly CrossrefQueryBuilder $queryBuilder,
        private readonly CrossrefResultMapper $mapper,
    ) {}

    /**
     * Resolve a normalized DOI.
     *
     * `null` means Crossref returned `404` (the DOI does not resolve). A
     * connection error, timeout or 5xx after retries throws.
     *
     * @throws CrossrefUnavailableException
     */
    public function findByDoi(string $normalizedDoi): ?CrossrefWorkData
    {
        $cached = Cache::remember(
            self::DOI_CACHE_PREFIX.$normalizedDoi,
            max(0, (int) config('services.crossref.cache_ttl', 86400)),
            function () use ($normalizedDoi): array {
                $payload = $this->request($this->queryBuilder->doiPath($normalizedDoi));

                // `404` is a result worth caching too: it bounds repeated lookups
                // of a broken DOI on retry without re-hitting Crossref.
                return $payload === null
                    ? ['found' => false, 'message' => null]
                    : ['found' => true, 'message' => $payload];
            },
        );

        if (! is_array($cached) || ($cached['found'] ?? false) !== true) {
            return null;
        }

        $message = $cached['message'] ?? null;

        return is_array($message) ? $this->mapper->mapSingle($message) : null;
    }

    /**
     * Bibliographic search for a reference. Not cached: responses depend on the
     * query and are recomputed per run (keeps evaluation reproducible).
     *
     * @return list<CrossrefWorkData>
     *
     * @throws CrossrefUnavailableException
     */
    public function searchBibliographic(ReferenceQuery $query): array
    {
        $payload = $this->request('works', $this->queryBuilder->searchParams($query));

        return $payload === null ? [] : $this->mapper->mapList($payload);
    }

    /**
     * Execute one GET with bounded retries.
     *
     * Retries only transient failures (connection errors and 5xx) with
     * exponential backoff; `404` is returned as `null` instead of an exception.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null decoded JSON, or `null` on a `404`
     *
     * @throws CrossrefUnavailableException
     */
    private function request(string $path, array $query = []): ?array
    {
        $query = array_merge($this->mailtoParams(), $query);

        try {
            $response = $this->pending()->retry(
                max(1, (int) config('services.crossref.retries', 2) + 1),
                fn (int $attempt): int => $this->backoffMilliseconds($attempt),
                fn (Throwable $exception): bool => $this->isTransient($exception),
            )->get(ltrim($path, '/'), $query);
        } catch (ConnectionException $exception) {
            throw CrossrefUnavailableException::serviceUnavailable($exception);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return null;
            }

            throw CrossrefUnavailableException::unexpectedResponse($exception->response->status(), $exception);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw CrossrefUnavailableException::unexpectedResponse($response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw CrossrefUnavailableException::unexpectedResponse($response->status());
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.crossref.base_url'), '/'))
            ->timeout(max(1, (int) config('services.crossref.timeout', 10)))
            ->connectTimeout(max(1, (int) config('services.crossref.connect_timeout', 5)))
            ->acceptJson()
            ->withUserAgent($this->userAgent());
    }

    /**
     * Whether a retry is worth attempting for the thrown exception.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException && $exception->response->serverError();
    }

    private function backoffMilliseconds(int $attempt): int
    {
        $base = max(0, (int) config('services.crossref.retry_backoff_ms', 200));

        return (int) ($base * (2 ** max(0, $attempt - 1)));
    }

    /**
     * @return array<string, string>
     */
    private function mailtoParams(): array
    {
        $mailto = trim((string) config('services.crossref.mailto'));

        return $mailto === '' ? [] : ['mailto' => $mailto];
    }

    /**
     * Polite-pool identification: the configured agent, or app name + contact.
     */
    private function userAgent(): string
    {
        $configured = trim((string) config('services.crossref.user_agent'));

        if ($configured !== '') {
            return $configured;
        }

        $mailto = trim((string) config('services.crossref.mailto'));

        return $mailto === ''
            ? sprintf('%s reference-verification', (string) config('app.name'))
            : sprintf('%s reference-verification (mailto:%s)', (string) config('app.name'), $mailto);
    }
}
