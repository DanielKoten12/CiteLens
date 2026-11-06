<?php

namespace App\Services\Metadata\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Positive/negative result cache shared by the HTTP metadata sources.
 *
 * Only **parsed** values are stored (arrays of mapped records, never raw
 * response bodies or error payloads): a cached entry can therefore never carry
 * a transport error forward as a fake result. An empty result is cached with the
 * shorter negative TTL so repeated runs are cheap while a newly indexed record
 * is picked up quickly. Exceptions are never cached: a failure is retried by
 * the next run and governed by the source's outage policy.
 *
 * Cache keys are owned by the caller and must include the source name and the
 * normalized query (`semantic_scholar:search:{sha1}`, `openalex:doi:{doi}`, …).
 */
final class SearchCache
{
    /**
     * Return the cached list or compute and cache it.
     *
     * @param  callable(): list<array<string, mixed>>  $fetch
     * @return list<array<string, mixed>>
     */
    public function remember(string $key, int $positiveTtl, int $negativeTtl, callable $fetch): array
    {
        if (Cache::has($key)) {
            $cached = Cache::get($key);

            return is_array($cached) ? array_values($cached) : [];
        }

        $value = $fetch();

        if (! is_array($value)) {
            return [];
        }

        $value = array_values($value);
        $ttl = max(0, $value === [] ? $negativeTtl : $positiveTtl);

        if ($ttl > 0) {
            Cache::put($key, $value, $ttl);
        }

        return $value;
    }
}
