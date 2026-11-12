<?php

namespace App\Services\Metadata\Support;

use App\Enums\MetadataSource;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed circuit breaker for **degradable** metadata sources.
 *
 * Fatal sources are never circuit-broken: their per-reference retry behavior is
 * part of OQ-03/D-08-03. For an optional source, paying the same timeout or
 * `429` for every document is pointless and can exhaust a daily budget, so after
 * `failure_threshold` consecutive failures the source is skipped for
 * `cooldown_seconds` across all workers. A success resets the counter.
 *
 * State is cache-backed on purpose: a per-process static would let each worker
 * rediscover the outage. A cache flush reopens the breaker, which only costs a
 * few extra requests.
 */
final class SourceCircuitBreaker
{
    private const KEY_PREFIX = 'metadata:circuit:';

    public function isOpen(MetadataSource $source): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return Cache::has($this->openKey($source));
    }

    public function recordFailure(MetadataSource $source): void
    {
        if (! $this->enabled()) {
            return;
        }

        $failures = (int) Cache::get($this->failuresKey($source), 0) + 1;

        if ($failures >= $this->failureThreshold()) {
            Cache::put($this->openKey($source), true, $this->cooldownSeconds());
            Cache::forget($this->failuresKey($source));

            return;
        }

        Cache::put($this->failuresKey($source), $failures, 3600);
    }

    public function recordSuccess(MetadataSource $source): void
    {
        if (! $this->enabled()) {
            return;
        }

        Cache::forget($this->failuresKey($source));
        Cache::forget($this->openKey($source));
    }

    private function enabled(): bool
    {
        return (bool) config('analysis.metadata.circuit_breaker.enabled', true);
    }

    private function failureThreshold(): int
    {
        return max(1, (int) config('analysis.metadata.circuit_breaker.failure_threshold', 3));
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('analysis.metadata.circuit_breaker.cooldown_seconds', 120));
    }

    private function openKey(MetadataSource $source): string
    {
        return self::KEY_PREFIX.$source->value.':open';
    }

    private function failuresKey(MetadataSource $source): string
    {
        return self::KEY_PREFIX.$source->value.':failures';
    }
}
