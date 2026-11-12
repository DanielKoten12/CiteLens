<?php

namespace App\Services\Metadata\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Global-per-source request throttle.
 *
 * A cache lock serializes workers, then only the remaining part of the
 * configured minimum interval is waited. This bounds the outbound request rate
 * across processes instead of letting each worker pace itself. A lock that
 * cannot be acquired within `$lockSeconds` is reported through the caller's
 * `$onLockTimeout` factory, so it becomes the source's own
 * `serviceUnavailable()` exception and the normal outage policy applies.
 *
 * Throttle bookkeeping lives in the cache, never in a per-process static: queue
 * workers are separate processes and must share the same pacing.
 */
final class RequestThrottle
{
    /**
     * Serialize one request through the named lock and honor the interval.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  callable(LockTimeoutException): mixed  $onLockTimeout  builds the source exception
     * @return T
     */
    public function run(
        string $lockName,
        int $minIntervalMs,
        callable $callback,
        callable $onLockTimeout,
        int $lockSeconds = 30,
        ?string $lastRequestKey = null,
        ?int $waitSeconds = null,
    ): mixed {
        $lastRequestKey ??= $lockName.':last_request_at';
        $lockSeconds = max(1, $lockSeconds);
        $waitSeconds = max(1, $waitSeconds ?? $lockSeconds);

        try {
            return Cache::lock($lockName, $lockSeconds)->block(
                $waitSeconds,
                function () use ($lastRequestKey, $minIntervalMs, $callback): mixed {
                    $this->waitForSlot($lastRequestKey, $minIntervalMs);

                    return $callback();
                },
            );
        } catch (LockTimeoutException $exception) {
            throw $onLockTimeout($exception);
        }
    }

    /**
     * Wait only the remaining part of the interval since the last request.
     */
    private function waitForSlot(string $lastRequestKey, int $minIntervalMs): void
    {
        $interval = max(0, $minIntervalMs);

        if ($interval === 0) {
            return;
        }

        $last = (int) Cache::get($lastRequestKey, 0);
        $waitMs = $interval - (now()->getTimestampMs() - $last);

        if ($waitMs > 0) {
            usleep((int) ($waitMs * 1000));
        }

        Cache::put($lastRequestKey, now()->getTimestampMs(), 3600);
    }
}
