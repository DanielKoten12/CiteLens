<?php

namespace App\Services\Garuda;

use App\Contracts\Metadata\BibliographicSource;
use App\Enums\MetadataSource;
use App\Exceptions\GarudaUnavailableException;
use App\Services\Metadata\ReferenceQuery;
use App\Services\Metadata\Support\RequestThrottle;
use App\Services\Metadata\Support\SearchCache;
use App\Services\Metadata\WorkMetadata;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Transport client for the Garuda search page (`garuda.kemdiktisaintek.go.id`).
 *
 * Garuda publishes no API, so this is a fallback source: the metadata chain
 * queries it only when the primary sources produced no plausible candidate
 * (`docs/plans/backend/08-garuda-fallback-source.md`). It owns politeness
 * (shared throttle, cache, UA), parsing and mapping — never verdicts.
 *
 * Failure policy (D-08-03): transport errors, unexpected statuses and pages
 * that no longer match the expected structure throw
 * {@see GarudaUnavailableException}. A page that explicitly reports
 * `Found 0 documents` is a cached empty result, not a failure.
 */
final class GarudaClient implements BibliographicSource
{
    private const CACHE_PREFIX = 'garuda:search:';

    private const THROTTLE_LOCK = 'garuda:throttle';

    private const LAST_REQUEST_KEY = 'garuda:last_request_at';

    private const USER_AGENT_FALLBACK = 'DafpusCek/1.0';

    public function __construct(
        private readonly GarudaSearchParser $parser,
        private readonly GarudaResultMapper $mapper,
        private readonly SearchCache $cache,
        private readonly RequestThrottle $throttle,
    ) {}

    public function source(): MetadataSource
    {
        return MetadataSource::Garuda;
    }

    public function enabled(): bool
    {
        return (bool) config('services.garuda.enabled', true);
    }

    /**
     * @return list<WorkMetadata>
     *
     * @throws GarudaUnavailableException
     */
    public function search(ReferenceQuery $query): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $title = $this->query($query);

        if ($title === null) {
            return [];
        }

        $cards = $this->cache->remember(
            self::CACHE_PREFIX.sha1($title),
            $this->ttl(),
            $this->negativeTtl(),
            fn (): array => $this->fetch($title),
        );

        return $this->map($cards);
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<WorkMetadata>
     */
    private function map(array $cards): array
    {
        return array_slice(
            $this->mapper->mapMany($cards, (string) config('services.garuda.base_url')),
            0,
            $this->rows(),
        );
    }

    /**
     * @return list<array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}>
     *
     * @throws GarudaUnavailableException
     */
    private function fetch(string $title): array
    {
        $response = $this->request($title);

        if ($response->failed()) {
            throw GarudaUnavailableException::unexpectedResponse($response->status());
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (! str_contains($contentType, 'text/html')) {
            throw GarudaUnavailableException::unexpectedFormat('content type '.$contentType);
        }

        $body = $response->body();

        if (strlen($body) > $this->maxResponseBytes()) {
            throw GarudaUnavailableException::unexpectedFormat('response too large');
        }

        $total = $this->parser->total($body);

        if ($total === null) {
            throw GarudaUnavailableException::unexpectedFormat('missing result marker');
        }

        $cards = $this->parser->parse($body);

        if ($total > 0 && $cards === []) {
            throw GarudaUnavailableException::unexpectedFormat('result marker disagrees with parsed cards');
        }

        return $cards;
    }

    /**
     * One request with bounded retries on connection errors and 5xx.
     *
     * @throws GarudaUnavailableException
     */
    private function request(string $title): Response
    {
        $attempts = $this->retries() + 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->throttle->run(
                    lockName: self::THROTTLE_LOCK,
                    minIntervalMs: $this->minIntervalMs(),
                    callback: fn (): Response => $this->pending()->get('/documents', ['q' => $title]),
                    onLockTimeout: static fn (LockTimeoutException $exception): GarudaUnavailableException => GarudaUnavailableException::serviceUnavailable($exception),
                    lockSeconds: max(10, $this->timeout() * ($this->retries() + 2)),
                    lastRequestKey: self::LAST_REQUEST_KEY,
                    waitSeconds: max(2, $this->timeout() + 2),
                );
            } catch (ConnectionException $exception) {
                $lastException = $exception;
                $this->backoff($attempt);

                continue;
            }

            if ($response->serverError() && $attempt < $attempts) {
                $this->backoff($attempt);

                continue;
            }

            return $response;
        }

        throw GarudaUnavailableException::serviceUnavailable($lastException);
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('services.garuda.retry_backoff_ms', 200));

        if ($base > 0) {
            usleep((int) ($base * (2 ** max(0, $attempt - 1)) * 1000));
        }
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.garuda.base_url'), '/'))
            ->timeout($this->timeout())
            ->connectTimeout(max(1, (int) config('services.garuda.connect_timeout', 5)))
            ->withUserAgent($this->userAgent())
            ->accept('text/html')
            ->withOptions([
                'allow_redirects' => [
                    'max' => max(0, (int) config('services.garuda.redirect_max', 3)),
                    'strict' => false,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                ],
            ]);
    }

    private function query(ReferenceQuery $query): ?string
    {
        $title = $query->title ?? $query->bibliographic;
        $title = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $title));
        $title = trim($title, " \t\n\r\0\x0B\"'");

        if (mb_strlen($title) < 4) {
            return null;
        }

        return mb_substr($title, 0, max(4, (int) config('services.garuda.query_max_length', 180)));
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('services.garuda.user_agent'));

        if ($configured !== '') {
            return $configured;
        }

        $mailto = trim((string) config('services.crossref.mailto'));

        return $mailto === ''
            ? self::USER_AGENT_FALLBACK
            : self::USER_AGENT_FALLBACK." (+mailto:{$mailto})";
    }

    private function retries(): int
    {
        return max(0, (int) config('services.garuda.retries', 1));
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.garuda.timeout', 10));
    }

    private function minIntervalMs(): int
    {
        return max(0, (int) config('services.garuda.min_interval_ms', 1000));
    }

    private function rows(): int
    {
        return max(1, (int) config('services.garuda.rows', 3));
    }

    private function ttl(): int
    {
        return max(0, (int) config('services.garuda.cache_ttl', 2592000));
    }

    private function negativeTtl(): int
    {
        return max(0, (int) config('services.garuda.negative_cache_ttl', 86400));
    }

    private function maxResponseBytes(): int
    {
        return max(1, (int) config('services.garuda.max_response_bytes', 2097152));
    }
}
