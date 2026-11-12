<?php

use App\Services\Metadata\Support\SearchCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->cache = app(SearchCache::class);
});

it('fetches once and serves the cached list', function () {
    $calls = 0;

    $fetch = function () use (&$calls): array {
        $calls++;

        return [['id' => 1]];
    };

    expect($this->cache->remember('test:key', 60, 60, $fetch))->toBe([['id' => 1]])
        ->and($this->cache->remember('test:key', 60, 60, $fetch))->toBe([['id' => 1]])
        ->and($calls)->toBe(1);
});

it('caches empty results with the negative ttl', function () {
    $calls = 0;

    $fetch = function () use (&$calls): array {
        $calls++;

        return [];
    };

    $this->cache->remember('test:key', 60, 30, $fetch);

    expect(Cache::has('test:key'))->toBeTrue()
        ->and($this->cache->remember('test:key', 60, 30, $fetch))->toBe([])
        ->and($calls)->toBe(1);
});

it('never caches exceptions', function () {
    $calls = 0;

    $fetch = function () use (&$calls): array {
        $calls++;

        if ($calls === 1) {
            throw new RuntimeException('boom');
        }

        return [['id' => 2]];
    };

    expect(fn () => $this->cache->remember('test:key', 60, 30, $fetch))->toThrow(RuntimeException::class)
        ->and($this->cache->remember('test:key', 60, 30, $fetch))->toBe([['id' => 2]])
        ->and($calls)->toBe(2);
});

it('does not cache when both ttls are zero', function () {
    $calls = 0;

    $fetch = function () use (&$calls): array {
        $calls++;

        return [];
    };

    $this->cache->remember('test:key', 0, 0, $fetch);
    $this->cache->remember('test:key', 0, 0, $fetch);

    expect($calls)->toBe(2)
        ->and(Cache::has('test:key'))->toBeFalse();
});
