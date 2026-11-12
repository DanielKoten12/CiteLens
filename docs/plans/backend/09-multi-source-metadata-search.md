# Phase 09 — Multi-Source Metadata Search (Semantic Scholar + OpenAlex)

> **Status:** implemented (Phase 09) — see `docs/ARCHITECTURE.md` §13 and `AGENTS.md` §14 for the
> verified repo status.
> **Depends on:** Phase 04 (Crossref verification/scoring, implemented), Phase 05 (citation
> resolution, implemented), Phase 08 (Garuda fallback + `BibliographicSource` seam, implemented)
> **Canonical references:** `docs/API_SPEC.md` §2.6/§5/§10, `docs/DB_SCHEMA.md`
> (`reference_finding_candidates.source`), `docs/ARCHITECTURE.md` §3/§7.1/§13,
> `docs/SECURITY.md` §1/§6/§7, `docs/TEST_PLAN.md` §5, `AGENTS.md` §3/§7/§8/§10/§11/§12/§15.
> Task template: `docs/plans/backend/README.md` §1.4.

This phase adds **two more bibliographic search sources** to the verification pipeline, in a
strictly ordered fallback chain:

```text
Crossref (primary, authoritative) → Garuda → Semantic Scholar → OpenAlex
```

The existing Phase 08 seam (`App\Contracts\Metadata\BibliographicSource`) was designed for this
hand-off ("Adding OpenAlex is one class + one config entry"). This plan keeps that promise but
first **fixes the seam's remaining leak**: today `ValidateReferencesStep` hard-codes the Garuda
fallback gate, its budget and its outage policy. Adding two more sources with the same
copy-paste would make the step unmaintainable. The plan therefore extracts a small, pure
**metadata source chain** and makes every source obey the same ordering, gating, failure, budget
and provenance rules.

---

## 1. Objective

Increase recall and DOI discovery for references that Crossref cannot match, without turning the
pipeline into a crawler or changing the public API shape:

| Before | After |
|---|---|
| Crossref miss → Garuda | Crossref miss → Garuda → Semantic Scholar → OpenAlex, stopping at the first **plausible** match |
| Only Crossref/Garuda candidates | Candidates from all enabled catalogues, each with `source` provenance |
| Garuda-specific fallback logic in the step | One generic chain policy; sources are transport + mapping only |
| A degraded optional source would look like "not indexed" | A degraded source is explicit: `pending` when nothing was found, never a false `not_found` |
| Local Indonesian work with no DOI: one chance (Garuda) | Garuda hit can be enriched/confirmed by a DOI-bearing Semantic Scholar or OpenAlex record |
| A DOI absent from Crossref is immediately `invalid` | A DOI absent from Crossref is tried against Semantic Scholar and OpenAlex before it is declared invalid; a resolver outage yields `pending`, never a false `invalid` |

The product outcome is still the proposal's three canonical cases: invalid/hallucinated DOI,
valid reference without a DOI (suggest a DOI), and citation/reference inconsistency. This phase
widens the *search* side **and** makes DOI resolution multi-source; scoring, citation resolution
and reports are unchanged.

---

## 2. Scope

**In:**

- Ordered, configurable metadata source chain (`Crossref → Garuda → Semantic Scholar → OpenAlex`).
- Ordered, configurable **DOI resolution chain** (`Crossref → Semantic Scholar → OpenAlex`; Garuda
  has no DOI lookup) so a DOI that Crossref does not index can still be confirmed (proposal case
  1) instead of being declared invalid on one catalogue's miss.
- Source enablement, per-source failure policy (env-overridable), per-source request budget,
  per-document total fallback budget/time cap.
- Semantic Scholar Graph API client + query builder + tolerant mapper + DOI resolver (key
  required).
- OpenAlex Works API client + query builder + tolerant mapper + DOI resolver (key required).
- Shared search cache, request throttle, 429/`Retry-After` handling and a per-source circuit
  breaker for degradable sources.
- Cross-source candidate merge/dedupe with DOI promotion; optional persisted-candidate cap.
- `MetadataSource::SemanticScholar` (`semantic_scholar`), API/DB/frontend type sync.
- Fixtures, fakes, unit/feature/e2e tests, docs and env/compose wiring.

**Out:**

- OpenAlex full-text/content downloads ($10/1000 requests, out of scope), PDF fetching, S2
  datasets, citation graph features.
- New public endpoints, admin UI for source toggles, per-user source preferences.
- Changing scoring weights, verdict thresholds, citation-resolution logic or report content.
- Google Scholar (still explicitly excluded by the proposal).
- DOCX support (still excluded by `API_SPEC.md` v1).

---

## 3. Baseline (verified against the repository and live APIs)

### 3.1 Repository

| Item | Current state |
|---|---|
| Pipeline step | `App\Services\Analysis\Steps\ValidateReferencesStep` (`crossref_validation`) is the only place external metadata search happens |
| DOI lookup | `CrossrefClient::findByDoi()` — the only DOI resolver today; `BibliographicSource` deliberately excludes DOI lookup |
| Search sources | `CrossrefClient` + `GarudaClient` implement `App\Contracts\Metadata\BibliographicSource` |
| Primary loop | `config('analysis.metadata.primary_sources')` = `[CrossrefClient::class]`; every primary is queried for every reference |
| Garuda fallback | Hard-coded in the step: `needsFallback()` gate (`scoring.fallback.title_threshold`), `services.garuda.max_requests_per_document` budget, `GarudaUnavailableException` fatal-first policy |
| Run state | `App\Services\Analysis\VerificationRunState` (`primaryResponded`, `garudaResponded`, `garudaLookups`) |
| Value object | `App\Services\Metadata\WorkMetadata` (+ `MetadataSource` provenance) |
| Candidate persistence | `ReferenceFindingWriter` is the single writer; no cap on candidate count |
| Candidate column | `reference_finding_candidates.source` is `varchar(20)` default `crossref` — **no migration needed** for `semantic_scholar` (16 chars) |
| API DTO | `ReferenceFindingCandidatePreviewData.source` is a `MetadataSource` enum |
| Frontend type | `frontend/src/types/api.ts` → `Candidate.source` union |
| Tests | `Http::preventStrayRequests()` active in `Tests\TestCase`; `GARUDA_ENABLED=false` in `phpunit.xml`; fixtures/fakes under `tests/Fixtures` + `tests/Support` |
| Progress | `crossref_validation` band 35–60; `ANALYSIS_TIMEOUT=900` |

### 3.2 Live API constraints (probed 2026-11; re-verify before release)

**Semantic Scholar**

| Item | Finding |
|---|---|
| Search endpoint | `GET https://api.semanticscholar.org/graph/v1/paper/search` |
| DOI endpoint | `GET https://api.semanticscholar.org/graph/v1/paper/DOI:{doi}` (200 with a paper, `404` when not found) |
| Parameters | `query` (plain text, **hyphenated terms yield no matches** per the swagger), `limit` (default 100, max 100), `fields`, `offset`, optional `year`, `publicationTypes`, `minCitationCount` |
| Response | `{ total, offset, next, data: [{ paperId, title, authors[{name}], year, venue, publicationVenue, journal, externalIds{DOI}, url, publicationTypes }] }` |
| Auth | optional `x-api-key`; **unauthenticated requests share a heavily throttled pool** (observed `429` on a plain probe) |
| Keyed limits | introductory rate limit **1 request/second** per key; DOI lookups count against the same limit |
| Terminal point | `externalIds.DOI` is the DOI signal on search results; the DOI endpoint replies `404` for a definitive miss |

**OpenAlex**

| Item | Finding |
|---|---|
| Search endpoint | `GET https://api.openalex.org/works?filter=title.search:<title>&per-page=N&select=...` |
| DOI endpoint | `GET https://api.openalex.org/works/doi:{doi}` (single-entity lookups are **free**; `404` when not found) |
| `title.search` | maps to `display_name.search`; **priced as a search** ($0.001/request), not as a cheap list filter |
| Cost | searches $1 / 1,000 requests; single-entity lookups (by ID/DOI) are free; keys start at `$1/day` (no key is only `$0.10/day` shared per IP) |
| Auth | `api_key` query param or `Authorization: Bearer`; a free key is required for real volume |
| Rate limits | 10 rps keyless, 30 rps keyed; daily-budget exhaustion and per-second limits both return `429` with `Retry-After` |
| Response | `{ meta, results: [{ id, doi, title, publication_year, authorships[{author{display_name}, raw_author_name}], primary_location{source{display_name}}, type }] }` |

Consequences baked into this plan:

- Semantic Scholar and OpenAlex are **both key-gated**. A missing key disables that source; the
  pipeline still works with Crossref + Garuda. This avoids silently burning a shared rate-limit
  pool or a keyless daily budget and presenting the resulting 429s as "not found".
- OpenAlex must be called with a **title search**, not a full-text `search=` (same price, worse
  precision), and DOI lookups are single-entity calls (free) rather than searches.
- Both APIs are cheap enough per request but the *aggregate* of four sources across a large
  bibliography is not: caching, throttling, early stop and budgets are part of the design, not
  polish.

---

## 4. Decisions

| ID | Question | Decision | Rationale |
|---|---|---|---|
| **D-09-01** | Chain vs. union | **Ordered fallback chain.** Crossref primary always runs; fallbacks run in order and stop when the stop policy is satisfied. Candidates collected up to that point are merged and scored. | This is the requested "preferred source" semantics, bounds external load, and keeps Garuda's existing role. Union-all would query four sources for every reference, including those Crossref already resolves. |
| **D-09-02** | Stop condition | Default `plausible_title_with_doi`: stop at the first candidate with title similarity ≥ `scoring.fallback.title_threshold` **and** a non-null DOI. `plausible_title` is a config option for the old behavior. | A Garuda hit often has no DOI (D-08-12); continuing to Semantic Scholar/OpenAlex is exactly how the system discovers a suggested DOI (proposal case 2). The alternative stops early and saves calls but loses DOI discovery. |
| **D-09-03** | DOI resolution | Add a **DOI resolution chain**: Crossref → Semantic Scholar → OpenAlex. Crossref is still tried first and is authoritative when it answers; a Crossref `404` is no longer automatically final. Garuda has no DOI lookup. A new `DoiLookup::Unknown` outcome means "no enabled resolver could answer" and maps to `pending`, never `invalid`. | The user confirmed DOI fallback is in scope. It fixes the false-`invalid` case where a DOI exists but Crossref does not index it, while an outage of a fallback resolver cannot turn into a false `invalid`. The contract (`API_SPEC.md` §2.6/OQ-02) must be updated deliberately. |
| **D-09-04** | Failure policy | **Env-configurable per source**, defaults: Crossref `fatal`, Garuda `fatal`, Semantic Scholar `degrade`, OpenAlex `degrade`. Values are `fatal|degrade`; an unknown value fails loudly at the first run. A degraded source never becomes `not_found`; if nothing was found, the reference is `pending` with that source's reason. | The user asked for configurability. Operators can make an optional source fatal or make Crossref/Garuda degrade without a code change. Defaults preserve existing OQ-03/D-08-03 behavior. |
| **D-09-05** | Where the chain lives | Extract `MetadataSourceRegistry`, `MetadataSourceChain`, `SourceChainState`, `SourceChainPolicy`, `SourceChainResult`, `WorkMetadataMerger` under `app/Services/Metadata/`. `ValidateReferencesStep` becomes a thin orchestrator (DOI lookup + chain + progress). | Avoids four copies of gate/budget/failure logic and makes the ordering testable without HTTP. Garuda's special case disappears. |
| **D-09-06** | Contract additions | `BibliographicSource` gains `enabled(): bool`. A separate `App\Contracts\Metadata\DoiResolver` (`source()`, `enabled()`, `resolve(string $normalizedDoi): ?WorkMetadata`) is implemented by Crossref, Semantic Scholar and OpenAlex; Garuda implements search only. A new `App\Contracts\Metadata\SourceUnavailableException` interface (extends `Throwable`, exposes `source(): MetadataSource`) is implemented by all four source exceptions. | DOI lookup and bibliographic search are different capabilities; making them one interface would force Garuda to fake a DOI resolver and every search source to expose a method it does not support. The chain skips disabled sources without calling them and maps outages without a `match` on every exception class. |
| **D-09-07** | Shared resilience helpers | `SearchCache` (positive/negative TTL), `RequestThrottle` (cache lock + min interval), `SourceCircuitBreaker` (failure threshold + cooldown) under `app/Services/Metadata/Support/`. 429 honors `Retry-After` only below a configurable cap; longer waits mark the source unavailable instead of sleeping in the worker. | Three HTTP sources with the same cache/throttle/429 shape should not each reinvent it. Long `usleep()` in a queue worker burns the analysis timeout. |
| **D-09-08** | Config model | Ordered `analysis.metadata.primary_sources` + `fallback_sources` + `doi_resolvers`; per-source `services.{semantic_scholar,openalex}` blocks; central `analysis.metadata.failure_policies` (env-overridable), budgets and stop policy. The **order is config-only**, never env-reordered. | Operators can disable a source, change its failure policy or change its limits without a deploy; changing the order changes verification semantics and deserves a config review. |
| **D-09-09** | Candidate merge | DOI-keyed dedupe first, normalized-title-keyed dedupe second, and **promote a DOI-bearing duplicate over an earlier DOI-less one** with the same normalized title. Optional persisted cap after ranking (`analysis.metadata.max_candidates_per_reference`, default 10, 0 = unlimited). | Four sources multiply near-duplicates; promotion fixes the "Garuda hit shadows the DOI-bearing record" failure mode, and the cap keeps API payloads/reports bounded. |
| **D-09-10** | Enum / schema | Add `MetadataSource::SemanticScholar = 'semantic_scholar'` (16 chars). **No migration**: the existing `varchar(20)` column and the enum cast accept it. Update `DB_SCHEMA.md` comment, API enums, frontend union. | Additive value, no schema semantics change. |
| **D-09-11** | Source-weighted scoring | No source-specific weight or bonus. Source order determines merge/tie order only; the scorer stays source-agnostic. | Keeps evaluation reproducible and avoids "Crossref bias" without evidence. A DOI-bearing candidate is not automatically better than a DOI-less one; metadata agreement decides. |
| **D-09-12** | Budgets | Per-source `max_requests_per_document` **plus** a document-wide `max_fallback_requests_per_document` (300) and `max_fallback_seconds` (300). On exhaustion, remaining gated references are `pending` with a budget reason; other sources may still answer. | `ANALYSIS_TIMEOUT` is 900 s. Garuda alone allows 500 lookups at 1 req/s (~500 s), so a fourth source without a global cap can push a pathological document past the timeout. |
| **D-09-13** | Test hermeticity | `SEMANTIC_SCHOLAR_ENABLED=false` and `OPENALEX_ENABLED=false` in `phpunit.xml` (both `<env>` and `<server>`); chain tests opt in explicitly and install fakes. | Existing tests must not start reaching the network because a new source was enabled by default. |
| **D-09-14** | Contract/docs sync | Update `API_SPEC.md`/`API_SPEC_ID.md`, `DB_SCHEMA.md`, `ARCHITECTURE.md`, `SECURITY.md`, `TEST_PLAN.md`, `PRODUCT_REQUIREMENTS.md`, `AGENTS.md` §11/§14/§15, env/compose, plans README in the same change. | `AGENTS.md` currently says OpenAlex is excluded and must not be added silently. This phase deliberately changes that decision; the docs must say so. |
| **D-09-15** | Frontend scope | Add `"semantic_scholar"` to the `Candidate.source` union; add **all four** sources (Crossref, Garuda, Semantic Scholar, OpenAlex) to the upload-page source chips and add a shared source-label map for future candidate badges. No API logic, no client-side decisions. | The user explicitly wants Garuda and OpenAlex visible as well. The chips are presentation copy; the backend still owns the verdict. |
| **D-09-16** | DOI verdict semantics | `Resolved` → existing `decideDoiResolved` (source-aware reason); `NotFound` → `invalid` with a generic "DOI tidak ditemukan pada sumber metadata." reason; `Unknown` → `pending` with a DOI-specific transient reason, even when candidates exist (they are persisted for review). | A DOI that is absent from Crossref but present in Semantic Scholar/OpenAlex is a valid DOI; a resolver outage must not be reported as a broken DOI. |
| **D-09-17** | Key-gated sources | Semantic Scholar **and** OpenAlex require an API key; both default to disabled when their key is absent. No keyless production path. | The user confirmed both use keys. This makes the 429/shared-budget failure mode impossible to reach by accident. |

---

## 5. Confirmed answers (no open questions)

All initial questions were answered by the user and are reflected in §4. This table is the
decision record; do not silently change an answer during implementation.

| # | Question | Confirmed answer |
|---|---|---|
| **Q1** | Does "preferred source" mean *stop at the first plausible source* or *query all and merge*? | **Stop at the first plausible source**. `plausible_title` vs `plausible_title_with_doi` remains configurable; default the latter. |
| **Q2** | Should a Semantic Scholar/OpenAlex outage fail the document or degrade? | **Degrade** by default, and make the failure policy **configurable per source** (`METADATA_FAILURE_POLICY_*`), so Crossref/Garuda can also be set to `degrade` deliberately. Defaults keep Crossref/Garuda `fatal`. |
| **Q3** | Should Semantic Scholar/OpenAlex resolve DOIs too? | **Yes.** DOI chain: Crossref → Semantic Scholar → OpenAlex. A resolver outage with no confirmation yields `pending`, never `invalid`. This is a deliberate contract change (see D-09-16 and the API spec updates). |
| **Q4** | Are API keys available? | **Both sources use an API key.** Semantic Scholar and OpenAlex are disabled when their key is absent; no keyless production path. |
| **Q5** | Cap persisted candidates per reference? | **Yes, 10 by default**, configurable, `0` = unlimited. |
| **Q6** | Keep Garuda before Semantic Scholar/OpenAlex despite ~1 req/s throttling? | **Keep the requested order** (`Crossref → Garuda → S2 → OpenAlex`). Semantic Scholar is also ~1 req/s keyed, and OpenAlex has a search budget; reordering would not make the chain faster enough to justify changing the requested preference. |
| **Q7** | Should the chain order be env-overridable? | **No.** Order lives in `config/analysis.php`; only enable/disable, failure policy and limits are env-tunable. |
| **Q8** | How much frontend work? | **Add all four sources**: `semantic_scholar` to the `Candidate.source` union, Crossref/Garuda/Semantic Scholar/OpenAlex chips on the upload page, and a shared source-label map for future candidate badges. No client-side verification logic. |

One implementation detail remains an explicit assumption rather than an unanswered question: the DOI
resolver order is **Crossref → Semantic Scholar → OpenAlex** (Garuda has no DOI endpoint). It is
kept in `analysis.metadata.doi_resolvers`, so it can be changed without touching the chain code.
If free single-entity lookups make OpenAlex a better second try, swap the two config entries; the
verdict semantics do not depend on the order.

---

## 6. Target architecture

### 6.1 Per-reference flow

```text
For each reference (bibliography order):
  1. DOI present and well-formed?
       → DOI resolution chain, in order: Crossref → Semantic Scholar → OpenAlex
         · first resolver that returns a work wins (work becomes a candidate)
         · all enabled resolvers answer null      → DoiLookup::NotFound
         · a resolver is unavailable and none won → DoiLookup::Unknown
       → malformed DOI: existing `Malformed` path (no network)
  2. ReferenceQuery::fromReference()
  3. Primary sources (Crossref search)  ── always runs, existing behavior
       └─ merge DOI candidate + search candidates (dedupe / promote)
  4. Stop policy satisfied?
       ├─ plausible title (+DOI per policy) → done
       └─ no → fallback chain, in order:
             Garuda (fatal) → Semantic Scholar (degrade) → OpenAlex (degrade)
             after each source: merge + check stop policy
  5. If DOI is Unknown, or no candidates at all AND a source failed
       → transientFailure + source/DOI-specific reason (verdict `pending`)
     If no candidates AND every enabled source answered → normal not_found
     (or suspicious/local venue)
  6. Progress report (existing), next reference
```

Network I/O only: durable rows are still written by `ScoreReferencesStep` through
`ReferenceFindingWriter`. No endpoint or database shape changes.

### 6.2 Classes and responsibilities

| Class | Responsibility |
|---|---|
| `App\Contracts\Metadata\BibliographicSource` | `source()`, `enabled()`, `search(ReferenceQuery): list<WorkMetadata>`. Transport + mapping only; never verdicts, never persistence. |
| `App\Contracts\Metadata\DoiResolver` | `source()`, `enabled()`, `resolve(string $normalizedDoi): ?WorkMetadata` (`null` = definitive miss). Implemented by Crossref, Semantic Scholar and OpenAlex; **not** Garuda. |
| `App\Contracts\Metadata\SourceUnavailableException` | Marker interface; `source(): MetadataSource`. Implemented by all four source exceptions. |
| `App\Services\Metadata\MetadataSourceRegistry` | Resolves the configured primary/fallback/DOI-resolver class lists from the container; validates the right contract per list, no duplicates, no unknown classes. |
| `App\Services\Metadata\DoiResolutionChain` | Runs the ordered DOI resolvers for one reference, applies the same failure/budget policy and returns a `DoiResolutionResult`. Holds **no** per-document state. |
| `App\Services\Metadata\DoiResolutionResult` | Immutable result: `lookup` (`DoiLookup`), `work`, `source` (which resolver answered), `pendingReason` for `Unknown`. |
| `App\Services\Metadata\MetadataSourceChain` | Runs primary then fallback **search** sources for one reference, applies stop/failure/budget policy, merges candidates, returns `SourceChainResult`. Holds **no** per-document state. |
| `App\Services\Metadata\SourceChainState` | Per-document mutable state: per-source lookups/responded/failure flags (`unavailable` is set only for `degrade` sources), total fallback count, start time, first failure reason. Shared by the DOI and search chains. Created in `handle()`, never a step property. |
| `App\Services\Metadata\SourceChainPolicy` | Pure decisions: `shouldGate()` (title length), `isSatisfied()` (title similarity + DOI policy), `failurePolicy(MetadataSource)`, `budgetExhausted(...)`. Reads env-overridable failure policies from `config/analysis.php`. |
| `App\Services\Metadata\SourceChainResult` | Immutable result: `candidates`, `transientFailure`, `pendingReason`, `consultedSources`. |
| `App\Services\Metadata\WorkMetadataMerger` | DOI/title dedupe, DOI promotion, deterministic order. Replaces the private `merge()`/`titleKey()` methods currently in the step. |
| `App\Services\Metadata\DoiLookup` | **Moved** from `Services/Crossref` (it is no longer Crossref-specific); gains `Unknown`. |
| `App\Services\Metadata\DoiNormalizer` | **Moved** from `Services/Crossref` (used by all source mappers); no behavior change. |
| `App\Services\Metadata\Support\SearchCache` | Positive/negative cache around a fetch callback (parsed arrays, never raw HTML/JSON errors). |
| `App\Services\Metadata\Support\RequestThrottle` | Global-per-source cache lock + minimum interval (`Cache::lock`), not `sleep()`. |
| `App\Services\Metadata\Support\SourceCircuitBreaker` | Per-source failure counter, open/cooldown state; used only for `degrade` sources. |
| `App\Services\SemanticScholar\{SemanticScholarClient, SemanticScholarQueryBuilder, SemanticScholarResultMapper}` | S2 search + DOI transport/query/mapping. |
| `App\Services\OpenAlex\{OpenAlexClient, OpenAlexQueryBuilder, OpenAlexResultMapper}` | OpenAlex search + DOI transport/query/mapping. |
| `ValidateReferencesStep` | DOI lookup + chain invocation + progress. No per-source `if` branches. |

`VerificationRunState` is replaced by `SourceChainState`; the existing `primaryResponded`,
`garudaResponded` and `garudaLookups` usages move to generic per-source methods.

### 6.3 Config model

`backend/config/analysis.php`:

```php
'metadata' => [
    /*
    | Ordered metadata sources. Primary sources are always queried (Crossref
    | search); fallbacks are consulted in order until the stop policy is met.
    | Order is intentionally config-only: changing it changes verification
    | provenance and evaluation reproducibility.
    */
    'primary_sources' => [
        CrossrefClient::class,
    ],

    'fallback_sources' => [
        GarudaClient::class,
        SemanticScholarClient::class,
        OpenAlexClient::class,
    ],

    /*
    | DOI resolution order. Crossref first; Garuda has no DOI endpoint and is
    | intentionally absent. A resolver returning null is a definitive miss; an
    | unavailable resolver that never answered makes the outcome Unknown.
    */
    'doi_resolvers' => [
        CrossrefClient::class,
        SemanticScholarClient::class,
        OpenAlexClient::class,
    ],

    // plausible_title | plausible_title_with_doi
    'stop_policy' => env('ANALYSIS_METADATA_STOP_POLICY', 'plausible_title_with_doi'),

    /*
    | Per-source outage policy: fatal | degrade. `fatal` fails the document on
    | the first failure of a run (existing OQ-03 behavior); `degrade` records
    | the failure, skips the source and lets the chain continue. Env-overridable
    | so an operator can change the policy without a deploy. Unknown values
    | fail loudly at the first run.
    */
    'failure_policies' => [
        'crossref' => env('METADATA_FAILURE_POLICY_CROSSREF', 'fatal'),
        'garuda' => env('METADATA_FAILURE_POLICY_GARUDA', 'fatal'),
        'semantic_scholar' => env('METADATA_FAILURE_POLICY_SEMANTIC_SCHOLAR', 'degrade'),
        'openalex' => env('METADATA_FAILURE_POLICY_OPENALEX', 'degrade'),
    ],

    'fallback' => [
        'max_requests_per_document' => (int) env('ANALYSIS_METADATA_MAX_FALLBACK_REQUESTS', 300),
        'max_seconds' => (int) env('ANALYSIS_METADATA_MAX_FALLBACK_SECONDS', 300),
    ],

    // Persisted candidates after ranking; 0 = unlimited.
    'max_candidates_per_reference' => (int) env('ANALYSIS_METADATA_MAX_CANDIDATES', 10),

    'circuit_breaker' => [
        'enabled' => (bool) env('ANALYSIS_METADATA_CIRCUIT_BREAKER', true),
        'failure_threshold' => (int) env('ANALYSIS_METADATA_CIRCUIT_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('ANALYSIS_METADATA_CIRCUIT_COOLDOWN', 120),
    ],
],
```

`backend/config/services.php` — Semantic Scholar:

```php
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
```

`backend/config/services.php` — OpenAlex:

```php
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
```

Notes:

- **Both sources are key-gated.** `enabled` for Semantic Scholar and OpenAlex defaults to "a key
  is configured". Leave `SEMANTIC_SCHOLAR_ENABLED` / `OPENALEX_ENABLED` commented out in
  `.env.example` so the key-presence default applies; setting them to an empty value disables the
  source unconditionally. Without keys the pipeline runs Crossref + Garuda exactly as today.
- **Failure policies are env-overridable** (`METADATA_FAILURE_POLICY_*`). Operators can make
  Semantic Scholar/OpenAlex fatal or make Crossref/Garuda degrade deliberately. Unknown values are
  rejected at the first run (fail loudly, never a silent `degrade`).
- **DOI resolver order is config-only** (`analysis.metadata.doi_resolvers`); Garuda is absent
  because it has no DOI endpoint. The default is Crossref → Semantic Scholar → OpenAlex.
- No key ever appears in a URL or log: S2 uses `x-api-key`, OpenAlex uses
  `Authorization: Bearer`. `mailto` is a non-secret contact param, same as Crossref.

### 6.4 DOI resolution chain

This is the deliberate contract change behind D-09-03/D-09-16. The goal is to stop declaring a DOI
invalid just because Crossref does not index it, while never converting a resolver outage into a
false `invalid`.

```php
// DoiResolutionChain::resolve()
$lookup = DoiLookup::NotFound;   // flipped to Unknown below if an enabled resolver never answered

foreach ($resolvers as $resolver) {
    // Disabled is not an outage: a disabled resolver is simply not part of the
    // enabled set, so it does not turn a completed miss into Unknown.
    if (! $resolver->enabled()) {
        continue;
    }

    if ($state->isUnavailable($resolver->source())
        || ($policy->isDegradable($resolver->source()) && $breaker->isOpen($resolver->source()))
        || ($policy->isFallback($resolver->source()) && $policy->budgetExhausted($resolver->source(), $state))) {
        $lookup = DoiLookup::Unknown;
        continue;
    }

    $state->recordLookup($resolver->source());

    try {
        $work = $resolver->resolve($normalizedDoi);
        $state->recordResponse($resolver->source());

        if ($policy->isDegradable($resolver->source())) {
            $breaker->recordSuccess($resolver->source());
        }

        if ($work !== null) {
            // The endpoint is keyed by DOI; if the mapper omitted it, the
            // normalized input DOI is the proof of resolution.
            return new DoiResolutionResult(
                lookup: DoiLookup::Resolved,
                work: $work->withDoi($normalizedDoi),
                source: $resolver->source(),
            );
        }

        // A completed null answer is a definitive miss for this resolver.
    } catch (SourceUnavailableException $exception) {
        $state->recordFailure($exception->source(), $exception->reason());
        $lookup = DoiLookup::Unknown;

        if ($policy->isFatal($exception->source())
            && ! $state->hasResponded($exception->source())) {
            throw $exception;
        }

        if ($policy->isDegradable($exception->source())) {
            $breaker->recordFailure($exception->source());
        }
    }
}

return new DoiResolutionResult(
    lookup: $lookup,
    work: null,
    source: null,
    pendingReason: $lookup === DoiLookup::Unknown
        ? MatchReasonBuilder::DOI_UNKNOWN
        : null,
);
```

Rules:

- **Crossref is first.** If Crossref resolves, Semantic Scholar/OpenAlex DOI endpoints are not
  called. When Crossref answers, its metadata remains the DOI authority.
- **A `404` is a definitive miss for that resolver**, not for the chain. The next enabled resolver
  is tried.
- **All enabled resolvers answered `null`** → `DoiLookup::NotFound` → `invalid` with the generic
  `"DOI tidak ditemukan pada sumber metadata."` reason.
- **Any enabled resolver failed or was skipped by budget/circuit and no resolver resolved** →
  `DoiLookup::Unknown` → `transientFailure` + `pending` with `DOI_UNKNOWN`. This is the critical
  difference between "the DOI is broken" and "we could not check it".
- **A resolver that is disabled is not an outage.** Crossref `404` + disabled S2/OpenAlex is still
  a definitive `NotFound`.
- **The resolved work becomes a normal candidate** with its source provenance and takes part in
  the existing scoring/DOI-match logic. If the DOI resolves to a different publication, the
  existing conflict path marks it `invalid` with the conflict reason.
- **Cache DOI lookups per source** (`crossref:doi:`, `semantic_scholar:doi:`, `openalex:doi:`),
  including `404` misses, exactly like Crossref does today. DOI misses are small and bounded.
- **Failure policies are shared** with the search chain (`METADATA_FAILURE_POLICY_*`). The default
  is Crossref `fatal`, Semantic Scholar/OpenAlex `degrade`; setting Crossref to `degrade` lets the
  DOI chain continue through S2/OpenAlex during a total Crossref outage (a deliberate operator
  choice, not the default OQ-03 behavior).
- **Budgets apply to fallback resolvers only.** Crossref DOI resolution is primary and is never
  skipped by the fallback request/time cap. Semantic Scholar and OpenAlex DOI lookups count toward
  their per-source budget and the document-wide fallback budget, because they are optional
  outbound calls.
- **Reason wording is source-aware** for a positive match (`forDoiMatch(MetadataSource)`), and
  generic for a miss (`DOI_NOT_FOUND`). Do not claim "Crossref" when OpenAlex answered.

### 6.5 Search chain runner pseudocode

```php
// ValidateReferencesStep::handle()
$sources = $this->registry->resolve();          // primary + fallback + DOI resolvers
$state = new SourceChainState(
    startMicros: hrtime(true),
    fallbackBudget: config('analysis.metadata.fallback.max_requests_per_document'),
    fallbackSeconds: config('analysis.metadata.fallback.max_seconds'),
);

foreach ($references as $index => $reference) {
    $verifications[] = $this->verifyReference($reference, $sources, $state);
    $this->progress->report($document, $this->step(), $index + 1, $total);
}

$context->setVerification(new ReferenceVerificationBatch($verifications));
```

```php
// MetadataSourceChain::search()
// Primary sources use the same run helper, but their failure policy is fatal
// and `state->hasResponded(Crossref)` reproduces the existing OQ-03 behavior:
// a first failure of the run throws, later failures degrade that reference.
$candidates = $this->run($sources->primary, $query, $reference, $state, existing: []);
$candidates = $this->merger->merge($existing, $candidates);

if ($this->policy->isSatisfied($candidates, $reference)) {
    return new SourceChainResult($candidates);
}

foreach ($sources->fallback as $source) {
    if ($this->policy->isSatisfied($candidates, $reference)) {
        break;
    }

    if (! $source->enabled()
        || $state->isUnavailable($source->source())
        || ($this->policy->isDegradable($source->source()) && $this->breaker->isOpen($source->source()))
        || $this->policy->budgetExhausted($source->source(), $state)) {
        continue;
    }

    $state->recordLookup($source->source());

    try {
        $candidates = $this->merger->merge($candidates, $source->search($query));
        $state->recordResponse($source->source());

        if ($this->policy->isDegradable($source->source())) {
            $this->breaker->recordSuccess($source->source());
        }
    } catch (SourceUnavailableException $exception) {
        $state->recordFailure($exception->source(), $exception->reason());

        if ($this->policy->isFatal($exception->source())
            && ! $state->hasResponded($exception->source())) {
            throw $exception;
        }

        if ($this->policy->isDegradable($exception->source())) {
            $this->breaker->recordFailure($exception->source());
        }
        // continue to the next fallback
    }
}

$transient = $candidates === [] && $state->firstFailureReason() !== null;

return new SourceChainResult(
    candidates: $candidates,
    transientFailure: $transient,
    pendingReason: $transient ? $state->firstFailureReason() : null,
    consultedSources: $state->consulted(),
);
```

Rules:

- **Primary sources always run** (Crossref search), even when the DOI chain already produced a
  candidate. This preserves D-04-02 and existing Crossref tests.
- **The DOI work is an ordinary candidate** passed into `$existing`; the search chain does not
  need a special case for it.
- **A DOI `Unknown` short-circuits the verdict, not the search.** The step still runs the search
  chain so candidates are persisted for manual review, then sets
  `transientFailure = true` and `pendingReason = DOI_UNKNOWN`. Candidate evidence is kept, but the
  verdict is `pending`.
- **Satisfaction is checked before every fallback** and after the primaries. A source that
  returns a weak candidate does not stop the chain.
- **No `transientFailure` when a candidate exists** and the DOI was resolved/absent. A degraded
  search source alongside a usable candidate must still produce a verdict; the degradation is
  logged and visible in the source provenance/reason only when it mattered.
- **A fatal source's first failure of the run rethrows** (existing OQ-03). Later failures are
  recorded and the chain continues to later sources; the reference becomes `pending` only if
  nothing at all was found. Fatal sources are **not** marked unavailable for the rest of the run,
  so the next reference retries them (the existing Crossref/Garuda per-reference degradation
  tests depend on this). Only `degrade` sources are marked unavailable/circuited.
- **Budget/time exhaustion** skips the remaining fallbacks; if no candidate exists, `pending`
  with a budget reason. It never becomes `not_found`.

### 6.6 Stop policy and matching gate

- Gate: reference title `mb_strlen(trim(...)) >= 8` (same as today). Shorter titles are too
  ambiguous; primary Crossref still runs.
- `plausible_title`: any candidate with `StringSimilarity::levenshtein($reference->title,
  $candidate->title) >= scoring.fallback.title_threshold` (default 0.85).
- `plausible_title_with_doi`: the same candidate **and** `candidate->doi !== null`.
- The check uses all candidates collected so far (DOI hit + previous sources), so a resolved DOI
  can prevent the entire fallback chain when its metadata matches.
- No new similarity metric; reuse `StringSimilarity` (pure, already injected in the scorer).

### 6.7 Merge and dedupe rules

`WorkMetadataMerger` replaces the private methods in the step:

1. **DOI first.** A normalized DOI identity wins; keep the first occurrence (source order).
2. **Title second, for DOI-less works.** Normalize with `StringSimilarity::normalize()`
   (lowercase, punctuation stripped, whitespace collapsed); drop later duplicates.
3. **DOI promotion.** If a later work has the same normalized title as an earlier DOI-less work
   but carries a DOI, replace the earlier entry (keep its position) and keep the richer metadata
   from both (non-null fields win; DOI always from the DOI-bearing record). This is what makes
   "Garuda found the local article, OpenAlex knows its DOI" surface as rank-1 with a DOI.
4. **Stable order.** Preserve source order; the scorer's existing tiebreak (confidence → DOI
   match → title signal → input index) then gives Crossref precedence on ties.
5. **Cap.** `ScoreReferencesStep` slices the ranked list to
   `analysis.metadata.max_candidates_per_reference` after scoring (rank 1..N preserved).
   Manual review is unaffected; a capped-out candidate simply does not exist for that finding.

### 6.8 Failure, throttling, caching and budgets

| Concern | Crossref (unchanged default) | Garuda (unchanged default) | Semantic Scholar | OpenAlex |
|---|---|---|---|---|
| Enabled check | always | `services.garuda.enabled` | `enabled` + non-empty key | `enabled` + non-empty key |
| Timeout / retries | 10 s / 2 | 10 s / 1 | 10 s / 1 | 10 s / 1 |
| Throttle | none (polite pool) | 1 req/s (cache lock) | 1.1 s keyed (cache lock) | 0.2 s keyed (cache lock) |
| Search cache | n/a | parsed cards, 30 d / 1 d negative | items, 30 d / 1 d negative | results, 30 d / 1 d negative |
| DOI cache | DOI hit/miss, existing TTL | n/a (no DOI endpoint) | `semantic_scholar:doi:`, 30 d / 1 d negative | `openalex:doi:`, 30 d / 1 d negative (free call) |
| 429 | not retried (treated as unexpected) | n/a | retry once if `Retry-After` ≤ cap, else throw | retry once if `Retry-After` ≤ cap, else throw |
| Failure policy (default) | `fatal` | `fatal` | `degrade` + circuit breaker | `degrade` + circuit breaker |
| Failure policy source | `METADATA_FAILURE_POLICY_CROSSREF` | `METADATA_FAILURE_POLICY_GARUDA` | `METADATA_FAILURE_POLICY_SEMANTIC_SCHOLAR` | `METADATA_FAILURE_POLICY_OPENALEX` |
| Per-document budget | none | 500 lookups | 200 lookups (search + DOI) | 200 lookups (search + DOI) |
| Global cap | — | — | 300 fallback lookups / 300 s per document, search **and** DOI | 300 fallback lookups / 300 s per document, search **and** DOI |

- **Failure policies are configurable.** `fatal` and `degrade` are both valid for every source;
  an invalid value makes the first analysis run fail loudly. The table lists defaults, not
  hardcoded behavior.
- **Cache the parsed item arrays**, not raw HTML/JSON errors. Errors are never cached.
- **Negative results are cached** with a shorter TTL (1 day) so repeated runs are cheap but a
  newly indexed paper is picked up quickly. A cached `404` DOI is a definitive miss for that
  resolver; the chain still tries the next resolver (its own cache decides).
- **Circuit breaker** (`degrade` sources only): after `failure_threshold` consecutive failures,
  the source is skipped for `cooldown_seconds` across workers (cache-backed). A success resets
  the counter. This prevents every document from paying the same 429/timeout. Fatal sources are
  not circuit-broken: their per-reference retry behavior is part of OQ-03/D-08-03.
- **`Retry-After` greater than `max_retry_after_seconds`** is not slept; the source is marked
  unavailable for the run/circuit. Queue workers must not block for a minute on one reference.
- **A DOI resolver outage is not a broken DOI.** If no enabled resolver resolved and at least one
  never answered, the DOI outcome is `Unknown` → `pending`. Only a completed miss by every enabled
  resolver is `NotFound` → `invalid`.
- **Logging** (`Log::warning`): source, HTTP status/exception class, document id, reference id,
  and whether the failure was in DOI resolution or search — never the API key, query text, DOI or
  response body.
- **`not_found`** now means "not found in the **enabled** metadata sources", not "not in
  Crossref". Update the API spec wording; a disabled source is not a failure.

### 6.9 Budget/time math (must stay under `ANALYSIS_TIMEOUT=900`)

Worst case per reference: Crossref search ~0.3 s + Garuda 1 s (throttle) + S2 1.1 s + OpenAlex
0.2 s ≈ 2.6 s, plus per-source HTTP time. A reference whose DOI misses Crossref adds at most two
more calls (S2 1.1 s, OpenAlex 0.2 s). A 30-reference document where every reference misses all
primaries: ~78 s of throttled fallback work. The global cap (300 s / 300 fallback lookups, DOI and
search combined) bounds pathological documents; typical documents gate far fewer references
because Crossref resolves most of them. Do not raise `GARUDA_MAX_REQUESTS_PER_DOCUMENT` or the
global fallback cap without re-checking the queue timeout and `DB_QUEUE_RETRY_AFTER`.

---

## 7. Source specifications

### 7.1 Semantic Scholar

**Query builder**

```php
final class SemanticScholarQueryBuilder
{
    public function searchParams(ReferenceQuery $query): array
    {
        // Normalization deliberately reuses StringSimilarity::normalize():
        // lowercase, punctuation stripped, hyphens become spaces.
        // S2 documents that hyphenated query terms yield no matches.
        $q = $this->strings->normalize($query->title ?? $query->bibliographic);
        $q = mb_substr(trim($q), 0, max(4, config('services.semantic_scholar.query_max_length', 300)));

        return [
            'query' => $q,
            'limit' => max(1, min(100, (int) config('services.semantic_scholar.limit', 5))),
            'fields' => 'title,authors,year,venue,publicationVenue,journal,externalIds,url,publicationTypes',
        ];
    }
}
```

- Use **title only**, not `bibliographic` (author/year dilute the relevance matcher; the scorer
  handles author/year). Same rationale as Garuda D-08-09.
- Search (not `/paper/search/match`): exact-match mode is too brittle for near titles.
- One request per gated reference; `limit=5` is enough for the scorer.

**Client**

- Implements `BibliographicSource` **and** `DoiResolver`.
- Search: `GET {base_url}/graph/v1/paper/search` with `searchParams()`.
- DOI: `GET {base_url}/graph/v1/paper/DOI:{normalizedDoi}` with the same `fields` (the endpoint
  returns a single paper object, not a `data` array; `404` = definitive miss).
- `x-api-key: {api_key}` (never in the query string), `Accept: application/json`,
  identified User-Agent (`DafpusCek/1.0 (+mailto:...)` or `SEMANTIC_SCHOLAR_USER_AGENT`).
- Non-JSON / oversized / missing `data` (search) or missing `paperId` (DOI) →
  `SemanticScholarUnavailableException::unexpectedFormat()`.
- `429` → `SemanticScholarUnavailableException::rateLimited($retryAfter)` after bounded retry.
- `5xx`/connection error → `::serviceUnavailable()` after bounded retry.
- `400` → log a warning and return `[]` / `null` (bad query data is per-reference, not a source
  outage).
- `401/403` → log loudly (credential problem) and throw `::unauthorized()` (failure policy applies).
- `enabled()` = configured + key present. A missing key means the client is never called.

**Mapper**

- Skip records with neither a DOI nor a title.
- `doi` ← `externalIds.DOI` → `DoiNormalizer`.
- `title` ← `title` (trim, collapse whitespace).
- `authors` ← `authors[].name` (filter empty).
- `containerTitle` ← `journal.name` → `publicationVenue.name` → `venue`.
- `publicationYear` ← `year` (1000–2100 guard).
- `url` ← `url` → `https://www.semanticscholar.org/paper/{paperId}`.
- `type` ← first `publicationTypes` entry (lowercased) or `null`.
- `source` ← `MetadataSource::SemanticScholar`.

**Exception**: `SemanticScholarUnavailableException` with
`serviceUnavailable()`, `unexpectedResponse(int)`, `unexpectedFormat(string)`,
`rateLimited(?int $retryAfter)`, `unauthorized()`; implements `SourceUnavailableException`.

### 7.2 OpenAlex

**Query builder**

```php
final class OpenAlexQueryBuilder
{
    public function searchParams(ReferenceQuery $query): array
    {
        $title = $this->strings->normalize($query->title ?? $query->bibliographic);
        $title = trim(mb_substr($title, 0, max(4, config('services.openalex.query_max_length', 300))));

        return array_filter([
            'filter' => 'title.search:'.$title,
            'per-page' => max(1, min(100, (int) config('services.openalex.per_page', 5))),
            'select' => 'id,doi,title,publication_year,authorships,primary_location,type',
            'mailto' => trim((string) config('services.openalex.mailto')) ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
```

- Use `title.search` (mapped by OpenAlex to `display_name.search`), **not** `search=` full text.
  Same price, much better precision; avoids matching a paper that merely cites the title.
- Do **not** hard-filter on `publication_year`: a typo in the reference year would exclude the
  correct record. Year remains a soft scoring signal (the scorer's existing behavior).
- Do **not** add `raw_author_name.search` as an AND filter for the same reason; the scorer
  compares authors.
- `select` keeps the payload small and bounds the response.

**Client**

- Implements `BibliographicSource` **and** `DoiResolver`.
- Search: `GET {base_url}/works` with `searchParams()`.
- DOI: `GET {base_url}/works/doi:{normalizedDoi}` with the same `select` (single-entity lookup,
  **free**, `404` = definitive miss).
- `Authorization: Bearer {api_key}` (never in the query string); `mailto` for the polite pool.
- Non-JSON / oversized / missing `results` (search) or missing `id` (DOI) →
  `OpenAlexUnavailableException::unexpectedFormat()`.
- `429` (daily budget or per-second) → retry once when `Retry-After` ≤ cap, otherwise
  `::rateLimited($retryAfter)`; the circuit breaker prevents repeated budget calls.
- `5xx`/connection error → `::serviceUnavailable()` after bounded retry.
- `400` → log and return `[]` / `null` (invalid filter data, per-reference, not an outage).
- `401/403` → `::unauthorized()` (bad/expired key); failure policy applies.
- `enabled()` = configured + non-empty key. A missing key means the client is never called.

**Mapper**

- Skip records with neither a DOI nor a title.
- `doi` ← `doi` (`https://doi.org/...`) → `DoiNormalizer`.
- `title` ← `title` → `display_name`.
- `authors` ← `authorships[].author.display_name` → `raw_author_name` fallback, in API order.
- `containerTitle` ← `primary_location.source.display_name`.
- `publicationYear` ← `publication_year` (1000–2100 guard).
- `url` ← `https://doi.org/{doi}` when a DOI exists, else `primary_location.landing_page_url`,
  else `id` (the OpenAlex record URL).
- `type` ← `type` (e.g. `article`, `book-chapter`).
- `source` ← `MetadataSource::OpenAlex`.

**Exception**: `OpenAlexUnavailableException` with the same factory set; implements
`SourceUnavailableException`.

### 7.3 Shared helpers

| Helper | Contract |
|---|---|
| `SearchCache` | `remember(string $key, int $positiveTtl, int $negativeTtl, callable(): array $fetch): array`; caches parsed arrays; empty results use the negative TTL; never caches exceptions. |
| `RequestThrottle` | `run(string $key, int $minIntervalMs, callable $callback): mixed`; cache lock serializes workers, then waits only the remaining interval; a lock timeout becomes the source's `serviceUnavailable()` exception. |
| `SourceCircuitBreaker` | `isOpen(MetadataSource): bool`, `recordFailure(MetadataSource): void`, `recordSuccess(MetadataSource): void`; cache keys `metadata:circuit:{source}:{open,failures}`; no-op when disabled. |
| Response guard | A private per-client `decodeJson(Response $response): array` checks status, content type (`json`), `max_response_bytes`, and JSON shape; throws the source's `unexpectedFormat()` otherwise. |

Garuda's existing throttle/cache code is refactored onto `RequestThrottle`/`SearchCache` with no
behavior change (its tests are the regression net). Crossref keeps its own retry/backoff (its
semantics — DOI 404 caching, polite pool — are different enough that sharing would obscure them).

---

## 8. Tasks

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight: enum, contracts (`enabled`, `DoiResolver`, source exception), move `DoiLookup`/`DoiNormalizer`, config skeleton, phpunit flags, registry | M | — | `refactor(backend): generalize metadata source and doi resolver seams` |
| **T1** | Chain extraction: state/policy/result/merger + DOI chain with Crossref only; step refactor to Crossref + Garuda/RG (behavior-preserving) | L | T0 | `refactor(backend): extract ordered metadata source and doi chains` |
| **T2** | Shared support: cache, throttle, circuit breaker; Garuda migrated | M | T1 | `refactor(backend): share metadata search resilience helpers` |
| **T3** | Semantic Scholar client + query builder + mapper + DOI resolver + exception + config | L | T2 | `feat(backend): add semantic scholar metadata source` |
| **T4** | OpenAlex client + query builder + mapper + DOI resolver + exception + config | L | T2 | `feat(backend): add openalex metadata source` |
| **T5** | Chain integration: stop/failure/budget policies, DOI fallback + `Unknown` verdict, candidate cap, reason wording, frontend source labels | L | T3, T4 | `feat(backend): chain metadata sources and doi fallbacks` |
| **T6** | Fixtures/fakes + unit/feature/e2e test matrix (search + DOI) | M | T5 | `test(backend): cover multi-source metadata and doi chains` |
| **T7** | Docs/contract/env/compose sync + final validation | S | T6 | `docs: document multi-source metadata search and doi fallback` |

### T0 — Preflight

1. Add `MetadataSource::SemanticScholar = 'semantic_scholar'` + `label()`; update
   `tests/Unit/EnumTest.php` pinned values.
2. Move `DoiLookup` and `DoiNormalizer` from `Services/Crossref` to `Services/Metadata` (mechanical
   namespace/import update; no behavior change). Add `DoiLookup::Unknown`.
3. Add `enabled()` to `BibliographicSource`; implement `true` in `CrossrefClient`, config-driven
   in `GarudaClient`. Update the contract docblock: DOI resolution lives in the separate
   `DoiResolver`; a source may implement both.
4. Add `App\Contracts\Metadata\DoiResolver`; make `CrossrefClient` implement it (rename
   `findByDoi()` → `resolve()`, update tests). Garuda does not implement it. Update the
   `MetadataSource` docblock: Crossref is authoritative, Garuda/Semantic Scholar/OpenAlex are
   supplemental sources in the configured chains.
5. Add `SourceUnavailableException` interface; implement in `CrossrefUnavailableException` and
   `GarudaUnavailableException` (each returns its `MetadataSource`).
6. Add `MetadataSourceRegistry` (resolve/validate primary, fallback and DOI-resolver lists). It
   must throw a `LogicException` for a class that does not implement the required contract, a
   duplicate source, or an unknown failure policy value.
7. Add `services.semantic_scholar` / `services.openalex` config blocks (key-gated enablement) and
   the `analysis.metadata` keys (`primary_sources`, `fallback_sources`, `doi_resolvers`,
   `stop_policy`, env-overridable `failure_policies`, budgets); add
   `SEMANTIC_SCHOLAR_ENABLED=false` + `OPENALEX_ENABLED=false` to `phpunit.xml` (`<env>` and
   `<server>` mirrors).
8. Confirm `Http::preventStrayRequests()` still catches any accidental call.

### T1 — Chain extraction (behavior-preserving)

1. Add `SourceChainState`, `SourceChainPolicy`, `SourceChainResult`, `WorkMetadataMerger`,
   `MetadataSourceChain`, `DoiResolutionChain`, `DoiResolutionResult`; move the step's
   `merge()`/`titleKey()` into the merger.
2. Refactor `ValidateReferencesStep` to use the registry + two chains with **only** Crossref DOI
   resolution, Crossref primary search and Garuda fallback enabled. Keep every existing assertion
   passing:
   - Crossref DOI hit/404/malformed semantics unchanged;
   - first Crossref failure fails the run; later failure → `transientFailure`;
   - Garuda first failure fails the run; later failure → `pending` with `GARUDA_TRANSIENT`;
   - Garuda budget exhaustion → `pending` with `GARUDA_BUDGET`;
   - `Found 0 documents` → miss;
   - strong Crossref candidate → zero Garuda requests;
   - deterministic reference order + progress.
3. Replace `VerificationRunState` with `SourceChainState`; keep `ReferenceVerification` unchanged
   except for `doiSource` (new, nullable).
4. No new HTTP; run the full suite. This is the proof that the two chains reproduce Phase 08
   behavior when only Crossref/Garuda are enabled.

### T2 — Shared resilience helpers

1. Add `SearchCache`, `RequestThrottle`, `SourceCircuitBreaker` (+ config validation for
   threshold/cooldown).
2. Migrate `GarudaClient` to the helpers. Do not change its cache keys/TTLs or throttle defaults;
   its existing tests (`GarudaClientTest`, `GarudaFallbackTest`, `GarudaFallbackEndToEndTest`)
   must pass unchanged except for new unit tests for the helpers.
3. Add a `SourceCircuitBreakerTest` (open after threshold, skip while open, reset on success).
4. Document the cache/throttle keys in the helper docblocks.

### T3 — Semantic Scholar

1. `SemanticScholarQueryBuilder`, `SemanticScholarResultMapper`,
   `SemanticScholarClient` (implements `BibliographicSource` + `DoiResolver`), exception, config.
2. DOI resolver: `GET /graph/v1/paper/DOI:{doi}`; cache hits/misses; map the single paper object.
3. Fixtures + `Tests\Support\SemanticScholarFake` (`results`, `empty`, `doiFound`, `doiMissing`,
   `unavailable`, `rateLimited`, `malformed`, `unauthorized`).
4. Unit tests: query normalization (hyphens → spaces, length cap, title-only), mapper (DOI,
   authors, venue fallbacks, year bounds, missing title), tolerant shapes.
5. Feature tests: one request/params/headers, key header not in URL, cache hit, negative cache,
   disabled without key, 429 retry/`Retry-After`, long `Retry-After` → throw, 5xx/timeout,
   non-JSON/oversized, 400 → `[]`/`null`, 401 → throw; DOI 200/404/timeout.
6. Manual smoke (documented, not a test): one live title and one live DOI with a key, log
   status/latency.

### T4 — OpenAlex

1. `OpenAlexQueryBuilder`, `OpenAlexResultMapper`, `OpenAlexClient` (implements
   `BibliographicSource` + `DoiResolver`), exception, config.
2. DOI resolver: `GET /works/doi:{doi}` (free single-entity lookup); cache hits/misses; map the
   single work object.
3. Fixtures + `Tests\Support\OpenAlexFake` (`results`, `empty`, `doiFound`, `doiMissing`,
   `unavailable`, `rateLimited`, `malformed`, `unauthorized`).
4. Unit tests: `title.search` filter (not `search=`), `per-page`/`select`, no year hard filter,
   mapper (DOI URL → normalized, authorships, venue, URL fallbacks, year bounds).
5. Feature tests: params/headers, `mailto`, `Authorization: Bearer` not in URL, cache/negative
   cache, disabled, 429/`Retry-After`/long wait, 5xx/timeout, malformed JSON, 400 → `[]`/`null`,
   circuit breaker after repeated 429s; DOI 200/404/timeout.
6. Manual smoke with a key; record the cost header/`meta.cost_usd` in the PR notes (DOI lookups
   should show as free).

### T5 — Chain integration

1. Wire the fallback and DOI-resolver lists; implement `plausible_title` /
   `plausible_title_with_doi`.
2. Implement env-overridable per-source failure policies, per-source and global budgets/time; map
   budget exhaustion and degraded failures to `pending` reasons (never `not_found`).
3. Implement DOI verdict semantics:
   - `DoiLookup::Resolved` + `doiSource` → `decideDoiResolved`;
   - `NotFound` → generic `DOI_NOT_FOUND`;
   - `Unknown` → `pending` with `DOI_UNKNOWN` (candidates still persisted);
   - source-aware `forDoiMatch(MetadataSource)`.
4. Add `MatchReasonBuilder` source-aware reasons:
   - keep `GARUDA_TRANSIENT`/`GARUDA_BUDGET` literals for Garuda (existing behavior/tests);
   - add `SemanticScholar`/`OpenAlex` transient/budget reasons (or a generic
     `transient(MetadataSource)` / `budget(MetadataSource)` factory that returns the existing
     Garuda strings for Garuda);
   - change `LOCAL_VENUE` to "Sumber tidak terindeks di katalog eksternal; perlu pemeriksaan
     manual." and `SUSPICIOUS` to "Judul pada metadata kandidat memiliki perbedaan." (they
     currently name Crossref, which is no longer the only source).
5. Add `AnalysisFailureHandler` safe messages for the new exceptions (only reachable when a
   source is configured `fatal`).
6. Apply the candidate cap in `ScoreReferencesStep` after ranking.
7. Frontend: add `semantic_scholar` to `Candidate.source`; add Crossref/Garuda/Semantic Scholar/
   OpenAlex chips to `UploadView.vue`; add a shared source-label map and update the `ResultView`
   mock reason that lists only Crossref/OpenAlex/Semantic Scholar.
8. Update existing fixtures/tests that assert the old Crossref-specific DOI/reason wording (e.g.
   `ReferenceDataTest`, `CrossrefVerificationEndToEndTest`, `GarudaFallbackEndToEndTest` where
   they hardcode a literal).

### T6 — Tests and fixtures

Minimum matrix (see §9). Add the search e2e tests to `tests/Feature/MultiSourceEndToEndTest.php`
and the DOI e2e tests to `tests/Feature/MultiSourceDoiEndToEndTest.php` (reuse
`InferenceFake::extractionFrom('inference/extract-garuda')` or add a fixture with a title/DOI
present in the S2/OpenAlex fixtures).

### T7 — Documentation and final validation

1. Contract docs: `API_SPEC.md` §2.6 DOI note/OQ-02 (multi-source DOI resolution + `Unknown`
   pending), `not_found` definition, §5 candidate `source` note and reason examples, §10 pipeline
   chains, §1 diagram; mirror in `API_SPEC_ID.md`.
2. `DB_SCHEMA.md`: `source` comment `crossref | openalex | garuda | semantic_scholar` (no
   migration).
3. `ARCHITECTURE.md` §3 components, pipeline §6/§7, §7.1 (source-agnostic scoring + DOI chain),
   §13 status.
4. `SECURITY.md`: assets (S2/OpenAlex keys), trust boundary/egress table, no keys in logs/URLs.
5. `TEST_PLAN.md`: new rows T-REF-13..T-REF-22 (search + DOI).
6. `PRODUCT_REQUIREMENTS.md` external metadata service/dependency rows.
7. `FRONTEND_INTEGRATION.md` source row + upload chips; `AGENTS.md` §11 external services, §12
   matching, §14 status, §15 discrepancy #2 (OpenAlex is now an approved source; Google Scholar
   stays excluded), §17 env.
8. `backend/.env.example`, root `.env.example` (both keys + failure-policy overrides),
   `compose.yaml` `x-backend-environment`, `docker/README.md`; `frontend/src/types/api.ts`,
   `frontend/src/views/UploadView.vue`, `frontend/src/lib/metadataSources.ts`.
9. `docs/plans/backend/README.md` phase-map row.
10. Validation: `vendor/bin/pint --dirty`, targeted Pest tests, full `php artisan test`,
    `npm run type-check` if the frontend type changed.

---

## 9. Test matrix

### Unit

| Test | Asserts |
|---|---|
| `SemanticScholarQueryBuilderTest` | title-only query; hyphen → space; punctuation/length cap; `limit`/`fields` bounded |
| `SemanticScholarResultMapperTest` | DOI normalized; authors/venue fallbacks; year bounds; missing title/DOI skipped; type mapping; `source = semantic_scholar` |
| `OpenAlexQueryBuilderTest` | `filter=title.search:` (not `search=`); `per-page`/`select`; no year filter; `mailto` omitted when empty |
| `OpenAlexResultMapperTest` | DOI URL normalized; authorships order/fallbacks; venue; URL fallbacks; year bounds; `source = openalex` |
| `WorkMetadataMergerTest` | DOI dedupe; title dedupe; DOI promotion; field merge; stable order |
| `SourceChainPolicyTest` | gate title length; `plausible_title` vs `..._with_doi`; failure policy lookup; **env override** for each source; invalid policy value throws; budget exhaustion |
| `DoiResolutionChainTest` | Crossref wins and stops the chain; Crossref 404 → S2; S2 404 → OpenAlex; all definitive misses → `NotFound`; any unanswered resolver → `Unknown`; disabled resolver is not an outage; failure-policy `fatal`/`degrade` behavior; budget/circuit skips → `Unknown` |
| `DoiLookupTest` | pinned values incl. `unknown` (internal enum, not API-visible) |
| `SourceCircuitBreakerTest` | open after threshold; skip while open; reset on success; disabled no-op |
| `SearchCacheTest` / `RequestThrottleTest` | positive/negative TTL; empty result caching; lock serialization; interval honored with `min_interval_ms=0` |
| `EnumTest` | `MetadataSource` values include `semantic_scholar` + label |
| `MatchReasonBuilderTest` | source-aware DOI match; generic DOI miss; `DOI_UNKNOWN`; no "Crossref" in a non-Crossref suspicious reason |

### Feature (HTTP fakes)

| Test | Asserts |
|---|---|
| `SemanticScholarClientTest` | search: documented params, `x-api-key` header and no key in URL, cache hit, 429 + `Retry-After`, long `Retry-After` throws, 5xx/timeout, non-JSON/oversized, 400 → `[]`, 401 throws, disabled/no key → zero requests; DOI: 200 maps one work, 404 → `null`, timeout/429 throws |
| `OpenAlexClientTest` | search: `title.search` params, Bearer header and no key in URL, `mailto`, cache/negative cache, 429/budget, 5xx/timeout, malformed, 400 → `[]`, disabled → zero requests; DOI: single-entity 200 maps one work, 404 → `null`, timeout/429 throws |
| `ValidateReferencesStepTest` (extended) | search chain: Crossref strong match → no fallback HTTP; Crossref miss + Garuda hit (no DOI) → S2 called only under `plausible_title_with_doi`; Garuda hit with DOI stops the chain; S2 hit stops OpenAlex; all miss → no candidates. DOI chain: Crossref hit → no S2/OpenAlex DOI calls; Crossref 404 + S2 hit → `Resolved`/`doiSource=semantic_scholar`; S2 404 + OpenAlex hit → `Resolved`/`doiSource=openalex`; all miss → `NotFound`; resolver outage + no hit → `Unknown`; disabled fallbacks → `NotFound`. Existing outage/budget/progress tests unchanged |
| `GarudaFallbackTest` | unchanged behavior with only Garuda enabled; Garuda failure after success still yields `pending` when nothing else is enabled |
| `MultiSourceEndToEndTest` | (a) Crossref+Garuda miss, S2 hit → `completed`, accepted finding, `source=semantic_scholar`; (b) only OpenAlex hit → `source=openalex`; (c) S2 429 degraded + OpenAlex hit → OpenAlex verdict, document completed; (d) all degraded + no candidates → `pending`, document completed, reason names the failed source; (e) all empty → `not_found`, document completed; (f) Garuda first-failure → document `failed` with the safe Garuda message |
| `MultiSourceDoiEndToEndTest` | (a) Crossref 404 + OpenAlex DOI hit with matching metadata → `completed`, `valid`, reason names OpenAlex; (b) Crossref 404 + S2 DOI hit with conflicting metadata → `completed`, `invalid`, conflict reason; (c) Crossref 404 + all enabled DOI resolvers down → `completed`, `pending`, never `invalid`; (d) Crossref 404 + all enabled DOI resolvers definitive miss → `completed`, `invalid`, generic DOI reason |
| `ReferenceEndpointTest` / `ReferenceDataTest` | candidate payload includes `source=semantic_scholar`/`openalex`; existing sources unchanged; reason strings updated |
| `DocumentAnalysisResetServiceTest`/`DocumentRetryTest` | new source flags do not affect retry/reset behavior |
| `AnalysisFailureHandlerTest` | new safe messages for S2/OpenAlex when configured fatal; no stack/URL/key leak |

### Acceptance rows for `docs/TEST_PLAN.md`

| ID | Scenario | Expected |
|---|---|---|
| T-REF-13 | Reference not in Crossref/Garuda, found in Semantic Scholar | candidate `source=semantic_scholar`, document `completed`, verdict `valid`/`suspicious` per score |
| T-REF-14 | Reference only in OpenAlex | candidate `source=openalex`, document `completed` |
| T-REF-15 | Earlier fallback satisfies the stop policy | later sources receive zero HTTP requests |
| T-REF-16 | Degradable source outage, no other candidate | reference `pending` (never `not_found`), document `completed` |
| T-REF-17 | Degradable source outage, later source has a candidate | normal verdict from the later source, document `completed` |
| T-REF-18 | `SEMANTIC_SCHOLAR_ENABLED=false` / `OPENALEX_ENABLED=false` | exact Crossref→Garuda behavior; zero requests to disabled sources; DOI chain reduces to Crossref only |
| T-REF-19 | DOI absent from Crossref but resolves in Semantic Scholar/OpenAlex with matching metadata | DOI is `Resolved`; verdict `valid`; reason names the resolver; document `completed` |
| T-REF-20 | DOI absent from Crossref, fallback DOI resolvers unavailable, no resolver confirms | `DoiLookup::Unknown` → `pending`; never `invalid`; document `completed` |
| T-REF-21 | DOI absent from Crossref, all enabled DOI resolvers answer `404` | `DoiLookup::NotFound` → `invalid` with generic DOI reason |
| T-REF-22 | `METADATA_FAILURE_POLICY_*` changes a source from `fatal` to `degrade` | behavior changes without code change; invalid value fails loudly at the first run |
| T-REF-23 | No Semantic Scholar/OpenAlex keys configured | both sources disabled; pipeline behaves exactly like Phase 08; no 429s, no false `invalid` |

### Test hermeticity

- New sources are disabled in `phpunit.xml`; every chain test opts in via `config([...])` and
  installs the fakes. `Http::preventStrayRequests()` remains the safety net.
- Set all throttles to `0` and retries to `0` in tests unless the test is specifically about
  retry/throttle. Use `Cache::flush()` in `beforeEach` for cache/circuit isolation.
- Fixtures contain published metadata only (no personal data beyond public author names).

---

## 10. Contract and documentation sync

| File | Change |
|---|---|
| `docs/API_SPEC.md` | §1 diagram; §2.6 DOI note/OQ-02 (DOI chain Crossref → Semantic Scholar → OpenAlex; `Unknown` → `pending`; generic "DOI tidak ditemukan pada sumber metadata."); `not_found` = no candidate in **enabled** sources; §5 `source` note adds `semantic_scholar`; §10 pipeline search **and** DOI chains; no endpoint shape change |
| `docs/API_SPEC_ID.md` | Mirror the above (English remains canonical) |
| `docs/DB_SCHEMA.md` | `source` comment lists four values; **no migration** (DOI outcomes are not persisted) |
| `docs/ARCHITECTURE.md` | §3 source components, §6/§7 search + DOI pipeline, §7.1 scoring stays source-agnostic, §13 status |
| `docs/SECURITY.md` | Assets (S2/OpenAlex API keys), trust-boundary/egress table, logging/no-key-in-URL rules |
| `docs/TEST_PLAN.md` | T-REF-13..23 + unit/feature/e2e rows |
| `docs/PRODUCT_REQUIREMENTS.md` | External metadata service row (Crossref, Garuda, Semantic Scholar, OpenAlex) + DOI-resolution dependency note |
| `docs/FRONTEND_INTEGRATION.md` | Source row no longer says "Crossref only"; upload-page chips list all four sources |
| `AGENTS.md` | §3 diagram/boundary, §11 external services (DOI authority is now a chain, with Crossref first), §12 matching split, §14 status, §15 discrepancy #2 resolved; Google Scholar still prohibited |
| `backend/.env.example` | `SEMANTIC_SCHOLAR_*`, `OPENALEX_*` placeholders, `METADATA_FAILURE_POLICY_*` overrides (never real values) |
| root `.env.example` | `SEMANTIC_SCHOLAR_API_KEY`, `OPENALEX_API_KEY`, `OPENALEX_MAILTO`, failure-policy overrides |
| `compose.yaml` | `x-backend-environment` passthrough for the new env vars |
| `docker/README.md` | Env list: S2/OpenAlex keys, failure-policy overrides and their purpose |
| `frontend/src/types/api.ts` | `source: "crossref" | "openalex" | "garuda" | "semantic_scholar"` |
| `frontend/src/lib/metadataSources.ts` | Shared source label map (Crossref, Garuda, Semantic Scholar, OpenAlex) for chips/badges |
| `frontend/src/views/UploadView.vue` | Source chips list **all four** sources; no Crossref-only claim |
| `frontend/src/views/ResultView.vue` | Mock reason mentioning sources includes Garuda too |
| `docs/plans/backend/README.md` | Phase 09 row |

No Composer/npm dependency is added. No migration is added.

The upload-page source chips are **descriptive copy**, not a runtime capability indicator: the
public API does not expose which metadata sources are enabled, and this phase must not add an
endpoint just to drive marketing copy. If a deployment intentionally disables a source, adjust the
copy at build time or leave the chip generic; never imply a disabled source verified a verdict.
The backend `source` field on each candidate is the authoritative provenance.

---

## 11. Files touched

**New**

```text
backend/app/Contracts/Metadata/DoiResolver.php
backend/app/Contracts/Metadata/SourceUnavailableException.php
backend/app/Services/Metadata/MetadataSourceRegistry.php
backend/app/Services/Metadata/MetadataSourceChain.php
backend/app/Services/Metadata/DoiResolutionChain.php
backend/app/Services/Metadata/DoiResolutionResult.php
backend/app/Services/Metadata/SourceChainState.php
backend/app/Services/Metadata/SourceChainPolicy.php
backend/app/Services/Metadata/SourceChainResult.php
backend/app/Services/Metadata/WorkMetadataMerger.php
backend/app/Services/Metadata/Support/SearchCache.php
backend/app/Services/Metadata/Support/RequestThrottle.php
backend/app/Services/Metadata/Support/SourceCircuitBreaker.php
backend/app/Services/SemanticScholar/SemanticScholarClient.php
backend/app/Services/SemanticScholar/SemanticScholarQueryBuilder.php
backend/app/Services/SemanticScholar/SemanticScholarResultMapper.php
backend/app/Services/OpenAlex/OpenAlexClient.php
backend/app/Services/OpenAlex/OpenAlexQueryBuilder.php
backend/app/Services/OpenAlex/OpenAlexResultMapper.php
backend/app/Exceptions/SemanticScholarUnavailableException.php
backend/app/Exceptions/OpenAlexUnavailableException.php
backend/tests/Feature/SemanticScholarClientTest.php
backend/tests/Feature/OpenAlexClientTest.php
backend/tests/Feature/MultiSourceEndToEndTest.php
backend/tests/Feature/MultiSourceDoiEndToEndTest.php
backend/tests/Unit/{SemanticScholarQueryBuilder,SemanticScholarResultMapper,OpenAlexQueryBuilder,OpenAlexResultMapper,WorkMetadataMerger,SourceChainPolicy,DoiResolutionChain,DoiLookup,SourceCircuitBreaker,SearchCache,RequestThrottle,MatchReasonBuilder}Test.php
backend/tests/Support/{SemanticScholarFake,OpenAlexFake}.php
backend/tests/Fixtures/semantic_scholar/{search-results,search-empty,doi-found,doi-missing,search-malformed,search-unauthorized}.json
backend/tests/Fixtures/openalex/{search-results,search-empty,doi-found,doi-missing,search-malformed,search-unauthorized}.json
frontend/src/lib/metadataSources.ts
docs/plans/backend/09-multi-source-metadata-search.md
```

**Modified**

```text
backend/app/Contracts/Metadata/BibliographicSource.php
backend/app/Enums/MetadataSource.php
backend/app/Services/Crossref/DoiLookup.php                  (moved → Services/Metadata)
backend/app/Services/Crossref/DoiNormalizer.php              (moved → Services/Metadata)
backend/app/Services/Crossref/CrossrefClient.php             (+ enabled, DoiResolver, exception marker)
backend/app/Services/Garuda/GarudaClient.php                 (+ enabled, shared helpers)
backend/app/Services/Analysis/Steps/ValidateReferencesStep.php (thin orchestrator)
backend/app/Services/Analysis/ReferenceVerification.php      (+ doiSource)
backend/app/Services/Analysis/VerificationRunState.php       (removed/replaced)
backend/app/Services/Scoring/{MatchReasonBuilder,VerdictDecider}.php
backend/app/Services/Analysis/Steps/ScoreReferencesStep.php  (candidate cap, pass doiSource)
backend/app/Exceptions/{CrossrefUnavailableException,GarudaUnavailableException}.php (marker + source())
backend/app/Services/Analysis/AnalysisFailureHandler.php     (new safe messages)
backend/config/{analysis,services}.php
backend/phpunit.xml
backend/.env.example
backend/tests/TestCase.php (only if a default fake is needed; otherwise unchanged)
backend/tests/Feature/{ValidateReferencesStepTest,GarudaFallbackTest,GarudaFallbackEndToEndTest,CrossrefVerificationEndToEndTest,ReferenceEndpointTest,ReferenceDataTest,AnalysisFailureHandlerTest}.php
backend/tests/Support/{AnalysisHarness,CrossrefFake,GarudaFake}.php (only if defaults change)
frontend/src/types/api.ts
frontend/src/views/UploadView.vue
frontend/src/views/ResultView.vue
docs/{API_SPEC,API_SPEC_ID,DB_SCHEMA,ARCHITECTURE,SECURITY,TEST_PLAN,PRODUCT_REQUIREMENTS,FRONTEND_INTEGRATION}.md
AGENTS.md
README.md (optional source list)
compose.yaml
.env.example
docker/README.md
docs/plans/backend/README.md
```

No new dependency, no migration, no route or response-envelope change. The only public-behavior
change is the deliberate DOI verdict semantics: a DOI that Crossref misses but an enabled fallback
resolver confirms is no longer `invalid`, and a resolver outage with no confirmation is `pending`
instead of `invalid`. That change is documented in `API_SPEC.md`/`API_SPEC_ID.md` and
`AGENTS.md`.

---

## 12. Risks and pitfalls

### 12.1 Correctness

- **Do not query all sources unconditionally.** The chain must stop at the first plausible
  match; otherwise load and duplicate candidates explode.
- **Do not treat a degraded source as a miss.** `not_found` requires that every enabled source
  answered and none matched. A 429/timeout with no candidate must be `pending` with a reason.
- **Do not promote a DOI-bearing candidate blindly.** Only promote when the normalized title
  matches; a DOI-bearing wrong paper must not outrank a correct DOI-less one.
- **Do not hard-filter by year** in OpenAlex/S2 queries; a wrong extracted year would hide the
  correct record. Year stays a soft scoring signal.
- **Do not use OpenAlex full-text `search=`.** It is the same price as `title.search` but matches
  papers that merely cite the title, polluting candidates.
- **Do not treat S2 `400` as a source outage.** It is per-reference query data; log and move on.
- **Do not use S2 `/paper/search/match`** as the only search: exact-match mode is too strict for
  punctuation/abbreviation variants.
- **Do not treat a DOI resolver's `404` as the whole chain's miss.** Only a completed miss by every **enabled** resolver is `NotFound`; an unanswered resolver makes it `Unknown` → `pending`.
- **Do not let a DOI resolver outage become `invalid`.** The difference between "the DOI is broken" and "we could not check it" is the main correctness requirement of D-09-16.
- **Do not override a Crossref-resolved DOI with a fallback resolver.** Crossref is first and wins when it answers; fallback resolvers are only consulted after a Crossref miss/failure.
- **Do not add source-specific scoring weights** without the Phase 07 evaluation harness; it
  makes results harder to reproduce and compare.

### 12.2 Operational

- **Rate limits are normal.** S2 is 1 rps keyed; OpenAlex is 10/30 rps but budgeted. Cache,
  throttle and the circuit breaker are mandatory, not optional.
- **Both sources require keys.** There is no keyless production path. A keyless OpenAlex call
  would only get `$0.10/day` shared per IP (one search = `$0.001`, about 100 searches/day), which
  is why the source is disabled without a key; a free key gives `$1/day` (~1,000 searches). DOI
  single-entity lookups are free. Track `meta.cost_usd`/`X-RateLimit-Remaining` in smoke logs; do
  not fetch full text/content.
- **`Retry-After` can be long.** Never `sleep()` past `max_retry_after_seconds` in a queue worker;
  mark the source unavailable and let the next document retry.
- **Budget vs `ANALYSIS_TIMEOUT`:** the global fallback cap (300 s / 300 lookups, DOI + search)
  must stay well below 900 s; Garuda's 1 req/s throttle is the dominant cost.
- **Cache key stability:** include the source name and the normalized query; changing the query
  normalizer invalidates the cache naturally (new hash), but a provider-wide change may serve
  stale data for up to 30 days. Document the TTL.
- **Circuit breaker persistence:** cache-backed; a cache flush reopens it (acceptable). Do not
  use a per-process static.

### 12.3 Security and privacy

- **Never put API keys in URLs**, logs, exception messages or fixtures. S2 `x-api-key`, OpenAlex
  `Authorization: Bearer`.
- **Only metadata**, never PDFs/full text; no OpenAlex content downloads, no S2 datasets.
- **Same egress rules as Crossref/Garuda:** queue workers only, never the frontend, never the
  inference service.
- **Logs carry ids/status, not reference text** (same rule as Garuda's D-08-* policy).
- **No secrets in `.env.example`**; placeholders only.

### 12.4 Spec discrepancies to report, not silently fix

- `AGENTS.md` §11/§15 currently forbid adding OpenAlex without a deliberate contract change. This
  phase **is** that deliberate change; update the docs in the same commit and call it out.
- **DOI authority changes.** `API_SPEC.md` §2.6/OQ-02 currently says a DOI absent from Crossref is
  `invalid`. This phase changes that to "invalid only when every enabled DOI resolver completes a
  miss; pending when a resolver could not answer". That is a deliberate contract change, not a bug
  fix.
- Semantic Scholar is not named in the proposal; it is an additive engineering choice. Note it
  as "extension beyond the proposal" in the summary/docs.
- The proposal mentions PDF+DOCX; v1 remains PDF-only. Do not touch DOCX.
- Google Scholar remains excluded; do not add it as a fallback.

---

## 13. Rollout and rollback

1. **Development default:** Crossref + Garuda as today; Semantic Scholar and OpenAlex are
   disabled until their keys are configured. Tests disable both new sources.
2. **Staging/demo with keys:** set `SEMANTIC_SCHOLAR_API_KEY` and `OPENALEX_API_KEY`; keep
   fallback budgets conservative; run the manual smoke (search + DOI) and the evaluation fixture.
3. **Production:** pin both keys in the environment (never in git); monitor source failure rates,
   OpenAlex budget headers and the DOI `Unknown` rate; keep the circuit breaker enabled.
4. **Rollback:** set `SEMANTIC_SCHOLAR_ENABLED=false` and/or `OPENALEX_ENABLED=false` — no
   deploy, no schema rollback. The search chain reverts to Crossref→Garuda and the DOI chain
   reverts to Crossref-only. The new `source` values remain readable and harmless. If the whole
   chain refactor must be reverted, the Phase 08 step wiring is the reference.

---

## 14. Acceptance criteria

1. A reference found only in Semantic Scholar resolves through the chain and persists
   `source = semantic_scholar`; the document completes.
2. A reference found only in OpenAlex resolves likewise with `source = openalex`.
3. The order is enforced and observable: when an earlier source satisfies the stop policy, later
   sources receive **zero** HTTP requests (asserted in tests).
4. A degradable source outage never produces `not_found`: with no other candidate the reference
   is `pending` with a source-specific reason and the document still completes; with a later
   candidate the verdict is normal.
5. A fatal source's first failure of the run still fails the document with the existing safe
   message; later failures degrade without a false `not_found`.
6. `SEMANTIC_SCHOLAR_ENABLED=false` and `OPENALEX_ENABLED=false` restore the exact Phase 08
   Crossref→Garuda behavior (zero requests, same reasons).
7. `php artisan test` passes with `Http::preventStrayRequests()`; `vendor/bin/pint --dirty`
   clean; `npm run type-check` clean if the frontend type changed.
8. `docs/API_SPEC.md` / `API_SPEC_ID.md` / `DB_SCHEMA.md` / `ARCHITECTURE.md` / `SECURITY.md` /
   `TEST_PLAN.md` / `AGENTS.md` reflect the new sources, the DOI chain and the deliberate OpenAlex
   change.
9. No API key appears in a URL, log or fixture; `.env.example` contains placeholders only.
10. The fallback budgets/time caps keep the worst-case document below `ANALYSIS_TIMEOUT`.
11. A DOI absent from Crossref but confirmed by Semantic Scholar/OpenAlex yields `valid` (or
    `invalid` with a conflict reason when the metadata disagrees); the reason names the resolver.
12. When an enabled DOI resolver cannot answer and no resolver confirms the DOI, the reference is
    `pending`, never `invalid`; when every enabled resolver completes a `404`, it is `invalid`
    with the generic DOI reason.
13. `METADATA_FAILURE_POLICY_*` overrides work without a code change and an invalid value fails
    loudly at the first run.
14. The upload page shows Crossref, Garuda, Semantic Scholar and OpenAlex; `semantic_scholar` is
    accepted by the frontend `Candidate.source` type.

---

## 15. Hand-off / future work

- **DOI fallback is now in scope** (Crossref → Semantic Scholar → OpenAlex). A future extension
  could add a DataCite/doi.org resolver for non-Crossref DOI registrations, but that is a separate
  contract change and needs its own outage policy.
- **Evaluation:** extend the Phase 07 harness with a metadata-source coverage report (Crossref
  only vs +Garuda vs +S2 vs +OpenAlex) and a DOI-resolution report (Crossref-only vs the full
  chain) using a frozen seed corpus. The current citation evaluation does not measure source or
  DOI recall.
- **Health probe:** an Artisan `metadata:probe` command (internal, not public) could check each
  configured source's search and DOI endpoint with one known title/DOI and report
  latency/status/budget. Useful before production launches; not required for this phase.
- **Crossref 429 handling:** Crossref currently retries only connection/5xx. If Crossref starts
  returning 429, add `Retry-After` handling to its client, reusing the shared helper.
