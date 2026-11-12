<?php

use App\Services\Crossref\CrossrefClient;
use App\Services\Garuda\GarudaClient;
use App\Services\OpenAlex\OpenAlexClient;
use App\Services\SemanticScholar\SemanticScholarClient;

return [

    /*
    |--------------------------------------------------------------------------
    | Document Analysis
    |--------------------------------------------------------------------------
    |
    | Queue orchestration for the asynchronous document-analysis pipeline
    | (`docs/API_SPEC.md` §10). This file owns the queue the job runs on, its
    | timeout, the overlap-lock buffer, the ordered metadata source chains, the
    | progress map and the extraction persistence bounds.
    |
    */

    // Queue the analysis job runs on.
    'queue' => env('ANALYSIS_QUEUE', 'default'),

    // Hard ceiling for a single analysis run, in seconds.
    'timeout' => (int) env('ANALYSIS_TIMEOUT', 900),

    // Extra seconds the overlap lock outlives the job timeout, so a worker that is
    // killed mid-run can never wedge a document behind an unexpired lock.
    'lock_expiry_buffer' => (int) env('ANALYSIS_LOCK_EXPIRY_BUFFER', 60),

    /*
    | Ordered metadata sources (`docs/plans/backend/09-multi-source-metadata-search.md`).
    |
    | Primary sources are always queried. Fallbacks are consulted in order until
    | the stop policy is satisfied. The order is intentionally config-only:
    | changing it changes verification provenance and evaluation
    | reproducibility, so it deserves a config review rather than an env toggle.
    |
    | Each entry must implement App\Contracts\Metadata\BibliographicSource
    | (fallbacks) or App\Contracts\Metadata\DoiResolver (doi_resolvers).
    */
    'metadata' => [
        'primary_sources' => [
            CrossrefClient::class,
        ],

        'fallback_sources' => [
            GarudaClient::class,
            SemanticScholarClient::class,
            OpenAlexClient::class,
        ],

        /*
        | DOI resolution order. Crossref is authoritative and always first.
        | Garuda has no DOI endpoint and is intentionally absent. A resolver
        | returning null is a definitive miss; an enabled resolver that could
        | not answer makes an otherwise-unresolved DOI `Unknown` (pending,
        | never invalid — D-09-16).
        */
        'doi_resolvers' => [
            CrossrefClient::class,
            SemanticScholarClient::class,
            OpenAlexClient::class,
        ],

        /*
        | When the fallback chain may stop:
        | plausible_title            any candidate with a plausible title match
        | plausible_title_with_doi   ... and a DOI (default: keeps searching for
        |                            a suggested DOI even after a Garuda hit)
        */
        'stop_policy' => env('ANALYSIS_METADATA_STOP_POLICY', 'plausible_title_with_doi'),

        /*
        | Per-source outage policy: fatal | degrade.
        |
        | `fatal` fails the document on the first failure of a run (existing
        | OQ-03 behavior); `degrade` records the failure, skips the source for
        | the rest of the run and lets the chain continue. Env-overridable so an
        | operator can change the policy without a deploy; an unknown value
        | fails loudly at the first run (never a silent degrade).
        */
        'failure_policies' => [
            'crossref' => env('METADATA_FAILURE_POLICY_CROSSREF', 'fatal'),
            'garuda' => env('METADATA_FAILURE_POLICY_GARUDA', 'fatal'),
            'semantic_scholar' => env('METADATA_FAILURE_POLICY_SEMANTIC_SCHOLAR', 'degrade'),
            'openalex' => env('METADATA_FAILURE_POLICY_OPENALEX', 'degrade'),
        ],

        /*
        | Document-wide fallback budget, covering search and DOI lookups of
        | optional sources. Bounds pathological documents well below
        | ANALYSIS_TIMEOUT; primary Crossref calls are never budgeted.
        | 0 = unlimited.
        */
        'fallback' => [
            'max_requests_per_document' => (int) env('ANALYSIS_METADATA_MAX_FALLBACK_REQUESTS', 300),
            'max_seconds' => (int) env('ANALYSIS_METADATA_MAX_FALLBACK_SECONDS', 300),
        ],

        /*
        | Persisted candidates per reference after ranking. Bounds API payloads
        | and reports; 0 = unlimited (D-09-09).
        */
        'max_candidates_per_reference' => (int) env('ANALYSIS_METADATA_MAX_CANDIDATES', 10),

        /*
        | Cache-backed circuit breaker for degradable sources only. Fatal sources
        | keep their per-reference retry behavior (OQ-03/D-08-03).
        */
        'circuit_breaker' => [
            'enabled' => (bool) env('ANALYSIS_METADATA_CIRCUIT_BREAKER', true),
            'failure_threshold' => (int) env('ANALYSIS_METADATA_CIRCUIT_THRESHOLD', 3),
            'cooldown_seconds' => (int) env('ANALYSIS_METADATA_CIRCUIT_COOLDOWN', 120),
        ],
    ],

    /*
    | Progress floors/ceilings per canonical step (`docs/API_SPEC.md` §2.6,
    | `docs/plans/backend/03-analysis-pipeline.md` §3.6). `AnalysisProgress`
    | interpolates inside `[floor, ceiling]` and only `complete()` reaches 100.
    */
    'progress' => [
        'queued' => ['floor' => 0, 'ceiling' => 0],
        'extracting' => ['floor' => 5, 'ceiling' => 25],
        'persisting' => ['floor' => 25, 'ceiling' => 35],
        'crossref_validation' => ['floor' => 35, 'ceiling' => 60],
        'embedding' => ['floor' => 60, 'ceiling' => 70],
        'scoring' => ['floor' => 70, 'ceiling' => 85],
        'resolving_citations' => ['floor' => 85, 'ceiling' => 95],
        'generating_report' => ['floor' => 95, 'ceiling' => 99],
        'completed' => ['floor' => 100, 'ceiling' => 100],
    ],

    /*
    | Bounds applied when persisting an extraction payload. Text is capped for
    | MySQL `text` portability; an over-long value is a fatal extraction error,
    | never a silent truncation. Years outside the range become null.
    */
    'extraction' => [
        'max_text_length' => (int) env('ANALYSIS_MAX_TEXT_LENGTH', 65535),
        'max_year' => 2100,
    ],

];
