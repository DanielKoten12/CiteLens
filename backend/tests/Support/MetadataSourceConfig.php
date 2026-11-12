<?php

namespace Tests\Support;

/**
 * Shared configuration for tests that exercise the ordered metadata chains.
 *
 * Mirrors the production defaults in `config/analysis.php` and
 * `config/services.php`, with throttles/back-off disabled so tests are fast and
 * deterministic. Every value can be overridden per test.
 */
final class MetadataSourceConfig
{
    /**
     * @param  array<string, mixed>  $overrides  dotted config keys
     */
    public static function apply(array $overrides = []): void
    {
        config(array_merge(self::defaults(), $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'services.crossref.mailto' => 'test@example.com',
            'services.crossref.base_url' => 'https://api.crossref.org',
            'services.crossref.retries' => 1,
            'services.crossref.retry_backoff_ms' => 0,
            'services.crossref.cache_ttl' => 60,

            'services.garuda.enabled' => true,
            'services.garuda.base_url' => 'https://garuda.kemdiktisaintek.go.id',
            'services.garuda.rows' => 3,
            'services.garuda.retries' => 0,
            'services.garuda.retry_backoff_ms' => 0,
            'services.garuda.min_interval_ms' => 0,
            'services.garuda.cache_ttl' => 60,
            'services.garuda.negative_cache_ttl' => 60,
            'services.garuda.max_requests_per_document' => 500,
            'services.garuda.max_response_bytes' => 2097152,
            'services.garuda.user_agent' => null,

            'services.semantic_scholar.enabled' => true,
            'services.semantic_scholar.api_key' => 'test-key',
            'services.semantic_scholar.base_url' => 'https://api.semanticscholar.org',
            'services.semantic_scholar.retries' => 0,
            'services.semantic_scholar.retry_backoff_ms' => 0,
            'services.semantic_scholar.min_interval_ms' => 0,
            'services.semantic_scholar.cache_ttl' => 60,
            'services.semantic_scholar.negative_cache_ttl' => 60,
            'services.semantic_scholar.max_requests_per_document' => 200,
            'services.semantic_scholar.max_response_bytes' => 2097152,
            'services.semantic_scholar.user_agent' => 'DafpusCek/1.0 (+mailto:test@example.com)',

            'services.openalex.enabled' => true,
            'services.openalex.api_key' => 'test-key',
            'services.openalex.mailto' => 'test@example.com',
            'services.openalex.base_url' => 'https://api.openalex.org',
            'services.openalex.retries' => 0,
            'services.openalex.retry_backoff_ms' => 0,
            'services.openalex.min_interval_ms' => 0,
            'services.openalex.cache_ttl' => 60,
            'services.openalex.negative_cache_ttl' => 60,
            'services.openalex.max_requests_per_document' => 200,
            'services.openalex.max_response_bytes' => 2097152,
            'services.openalex.user_agent' => 'DafpusCek/1.0 (mailto:test@example.com)',

            'analysis.metadata.stop_policy' => 'plausible_title_with_doi',
            'analysis.metadata.failure_policies' => [
                'crossref' => 'fatal',
                'garuda' => 'fatal',
                'semantic_scholar' => 'degrade',
                'openalex' => 'degrade',
            ],
            'analysis.metadata.fallback.max_requests_per_document' => 300,
            'analysis.metadata.fallback.max_seconds' => 300,
            'analysis.metadata.max_candidates_per_reference' => 10,
            'analysis.metadata.circuit_breaker.enabled' => true,
            'analysis.metadata.circuit_breaker.failure_threshold' => 3,
            'analysis.metadata.circuit_breaker.cooldown_seconds' => 120,

            'scoring.fallback.title_threshold' => 0.85,
        ];
    }
}
