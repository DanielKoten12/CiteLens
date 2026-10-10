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
