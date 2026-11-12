<?php

use App\Services\Metadata\Support\RequestThrottle;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->throttle = app(RequestThrottle::class);
});

it('runs the callback and releases the lock when no interval is configured', function () {
    $result = $this->throttle->run(
        lockName: 'test:throttle',
        minIntervalMs: 0,
        callback: fn (): string => 'answer',
        onLockTimeout: fn (Throwable $exception): RuntimeException => new RuntimeException('timeout', previous: $exception),
        lastRequestKey: 'test:last_request_at',
    );

    expect($result)->toBe('answer')
        ->and(Cache::has('test:last_request_at'))->toBeFalse();
});

it('waits the remaining interval between requests', function () {
    $this->throttle->run(
        lockName: 'test:throttle',
        minIntervalMs: 80,
        callback: fn (): string => 'first',
        onLockTimeout: fn (Throwable $exception): RuntimeException => new RuntimeException('timeout', previous: $exception),
        lastRequestKey: 'test:last_request_at',
    );

    $startedAt = microtime(true);

    $this->throttle->run(
        lockName: 'test:throttle',
        minIntervalMs: 80,
        callback: fn (): string => 'second',
        onLockTimeout: fn (Throwable $exception): RuntimeException => new RuntimeException('timeout', previous: $exception),
        lastRequestKey: 'test:last_request_at',
    );

    expect((microtime(true) - $startedAt) * 1000)->toBeGreaterThanOrEqual(50.0);
});

it('maps a lock timeout to the caller exception factory', function () {
    $lock = Cache::lock('test:throttle', 10);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $this->throttle->run(
            lockName: 'test:throttle',
            minIntervalMs: 0,
            callback: fn (): string => 'never',
            onLockTimeout: fn (Throwable $exception): RuntimeException => new RuntimeException('source unavailable', previous: $exception),
            lockSeconds: 10,
            waitSeconds: 1,
        ))->toThrow(RuntimeException::class, 'source unavailable');
    } finally {
        $lock->release();
    }
});
