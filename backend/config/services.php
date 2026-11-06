<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Crossref REST API (`docs/API_SPEC.md` §1/§10). Unauthenticated: the polite
    | pool is identified by the contact mailto and a descriptive User-Agent (OQ-11).
    */
    'crossref' => [
        'base_url' => env('CROSSREF_BASE_URL', 'https://api.crossref.org'),
        'mailto' => env('CROSSREF_MAILTO'),
        'timeout' => (int) env('CROSSREF_TIMEOUT', 10),
        'connect_timeout' => (int) env('CROSSREF_CONNECT_TIMEOUT', 5),
        'rows' => (int) env('CROSSREF_ROWS', 5),
        'cache_ttl' => (int) env('CROSSREF_CACHE_TTL', 86400),
        'retries' => (int) env('CROSSREF_RETRIES', 2),
        'retry_backoff_ms' => (int) env('CROSSREF_RETRY_BACKOFF_MS', 200),
        'user_agent' => env('CROSSREF_USER_AGENT'),
    ],

    /*
    | Garuda (Garba Rujukan Digital) — Indonesian fallback metadata source.
    | No public API: the client parses the server-rendered search page. Queried
    | only when the primary sources return no plausible candidate. Every
    | politeness knob is env-tunable (throttle, cache, rows, budget).
    */
    'garuda' => [
        'enabled' => (bool) env('GARUDA_ENABLED', true),
        'base_url' => env('GARUDA_BASE_URL', 'https://garuda.kemdiktisaintek.go.id'),
        'timeout' => (int) env('GARUDA_TIMEOUT', 10),
        'connect_timeout' => (int) env('GARUDA_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('GARUDA_RETRIES', 1),
        'retry_backoff_ms' => (int) env('GARUDA_RETRY_BACKOFF_MS', 200),
        'rows' => (int) env('GARUDA_ROWS', 3),
        'query_max_length' => (int) env('GARUDA_QUERY_MAX_LENGTH', 180),
        'cache_ttl' => (int) env('GARUDA_CACHE_TTL', 2592000),
        'negative_cache_ttl' => (int) env('GARUDA_NEGATIVE_CACHE_TTL', 86400),
        'min_interval_ms' => (int) env('GARUDA_MIN_INTERVAL_MS', 1000),
        'redirect_max' => (int) env('GARUDA_REDIRECT_MAX', 3),
        'max_requests_per_document' => (int) env('GARUDA_MAX_REQUESTS_PER_DOCUMENT', 500),
        'max_response_bytes' => (int) env('GARUDA_MAX_RESPONSE_BYTES', 2097152),
        'user_agent' => env('GARUDA_USER_AGENT'),
    ],

    /*
    | Semantic Scholar Graph API — supplemental metadata source
    | (`docs/plans/backend/09-multi-source-metadata-search.md`). Key-gated: the
    | source is disabled when no API key is configured (D-09-17), because
    | unauthenticated requests share a heavily throttled pool whose 429s would
    | otherwise be mistaken for "not indexed". The key is sent as `x-api-key`,
    | never in the query string.
    */
    'semantic_scholar' => [
        'enabled' => (bool) env(
            'SEMANTIC_SCHOLAR_ENABLED',
            trim((string) env('SEMANTIC_SCHOLAR_API_KEY', '')) !== '',
        ),
        'api_key' => env('SEMANTIC_SCHOLAR_API_KEY'),
        'base_url' => env('SEMANTIC_SCHOLAR_BASE_URL', 'https://api.semanticscholar.org'),
        'timeout' => (int) env('SEMANTIC_SCHOLAR_TIMEOUT', 10),
        'connect_timeout' => (int) env('SEMANTIC_SCHOLAR_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('SEMANTIC_SCHOLAR_RETRIES', 1),
        'retry_backoff_ms' => (int) env('SEMANTIC_SCHOLAR_RETRY_BACKOFF_MS', 300),
        'max_retry_after_seconds' => (int) env('SEMANTIC_SCHOLAR_MAX_RETRY_AFTER', 5),
        'limit' => (int) env('SEMANTIC_SCHOLAR_LIMIT', 5),
        'query_max_length' => (int) env('SEMANTIC_SCHOLAR_QUERY_MAX_LENGTH', 300),
        'cache_ttl' => (int) env('SEMANTIC_SCHOLAR_CACHE_TTL', 2592000),
        'negative_cache_ttl' => (int) env('SEMANTIC_SCHOLAR_NEGATIVE_CACHE_TTL', 86400),
        'min_interval_ms' => (int) env('SEMANTIC_SCHOLAR_MIN_INTERVAL_MS', 1100),
        'max_requests_per_document' => (int) env('SEMANTIC_SCHOLAR_MAX_REQUESTS_PER_DOCUMENT', 200),
        'max_response_bytes' => (int) env('SEMANTIC_SCHOLAR_MAX_RESPONSE_BYTES', 2097152),
        'user_agent' => env('SEMANTIC_SCHOLAR_USER_AGENT'),
    ],

    /*
    | OpenAlex Works API — supplemental metadata source. Key-gated for the same
    | reason as Semantic Scholar: keyless access has a tiny shared daily budget.
    | The key is sent as `Authorization: Bearer`, never in the query string;
    | `mailto` is the non-secret polite-pool contact.
    */
    'openalex' => [
        'enabled' => (bool) env(
            'OPENALEX_ENABLED',
            trim((string) env('OPENALEX_API_KEY', '')) !== '',
        ),
        'api_key' => env('OPENALEX_API_KEY'),
        'mailto' => env('OPENALEX_MAILTO', env('CROSSREF_MAILTO')),
        'base_url' => env('OPENALEX_BASE_URL', 'https://api.openalex.org'),
        'timeout' => (int) env('OPENALEX_TIMEOUT', 10),
        'connect_timeout' => (int) env('OPENALEX_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('OPENALEX_RETRIES', 1),
        'retry_backoff_ms' => (int) env('OPENALEX_RETRY_BACKOFF_MS', 300),
        'max_retry_after_seconds' => (int) env('OPENALEX_MAX_RETRY_AFTER', 5),
        'per_page' => (int) env('OPENALEX_PER_PAGE', 5),
        'query_max_length' => (int) env('OPENALEX_QUERY_MAX_LENGTH', 300),
        'cache_ttl' => (int) env('OPENALEX_CACHE_TTL', 2592000),
        'negative_cache_ttl' => (int) env('OPENALEX_NEGATIVE_CACHE_TTL', 86400),
        'min_interval_ms' => (int) env('OPENALEX_MIN_INTERVAL_MS', 200),
        'max_requests_per_document' => (int) env('OPENALEX_MAX_REQUESTS_PER_DOCUMENT', 200),
        'max_response_bytes' => (int) env('OPENALEX_MAX_RESPONSE_BYTES', 2097152),
        'user_agent' => env('OPENALEX_USER_AGENT'),
    ],

    /*
    | Internal FastAPI inference service (`docs/API_SPEC.md` §9). Private network only;
    | never exposed to the frontend.
    */
    'inference' => [
        'base_url' => env('INFERENCE_BASE_URL', 'http://inference:8000'),
        'timeout' => (int) env('INFERENCE_TIMEOUT', 120),
        'connect_timeout' => (int) env('INFERENCE_CONNECT_TIMEOUT', 5),
        'embedding_batch_size' => (int) env('INFERENCE_EMBEDDING_BATCH_SIZE', 32),
    ],

    /*
    | Gotenberg HTML→PDF rendering (`docs/API_SPEC.md` §8). Internal service on the
    | private network, exactly like inference: never exposed, never called by the browser.
    */
    'gotenberg' => [
        'base_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 60),
        'connect_timeout' => (int) env('GOTENBERG_CONNECT_TIMEOUT', 5),
    ],

];
