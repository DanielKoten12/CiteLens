# Phase 04 — Crossref Verification, Candidate Ranking & Scoring (Detailed Implementation Plan)

> **Status:** detailed plan — ready to implement
> **Parent:** [`04-crossref-verification-and-scoring.md`](04-crossref-verification-and-scoring.md)
> **Depends on:** [Phase 03](03-analysis-pipeline-detail.md) (implemented) · **Unblocks:** Phase 05
> Canonical references: `docs/API_SPEC.md` §2.6/§5/§10/§11, `docs/ARCHITECTURE.md` §7/§7.1/§10,
> `docs/PRODUCT_REQUIREMENTS.md` §6.3/§9/§10, `docs/TEST_PLAN.md` T-REF-07/08, T-SCORE-01..07,
> T-PIPE-03, `docs/SECURITY.md` §1/§6/§10, `AGENTS.md` §3/§8/§11/§12/§14.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `04-crossref-verification-and-scoring.md` into executable tasks. It keeps the
phase boundary: it implements **Crossref retrieval + candidate ranking + scoring + finding
persistence** and **does not** implement citation resolution or the review/findings endpoints
(Phase 05), report generation (Phase 06) or the FastAPI service (OQ-13). It adds **no migration** —
the OQ-14 unique index already exists.

The design keeps four hard rules from the proposal and `AGENTS.md`:

1. **Network in one place.** Crossref is only called by `ValidateReferencesStep`; scoring is pure
   and offline. No step after `crossref_validation` performs HTTP to Crossref.
2. **One decision.** The verdict for every reference is produced by exactly one class
   (`VerdictDecider`); nothing re-implements the matrix.
3. **SBERT is a signal, never the verdict.** Semantic similarity only contributes a weighted term
   and degrades cleanly when `/v1/embeddings` is unavailable (OQ-04).
4. **No schema/contract change silently.** The schema already carries every column used;
   `docs/API_SPEC.md` only gains clarifying notes (OQ-02/OQ-11 text), never a new shape.

> **Read first.** The two cross-phase questions — how a "total Crossref outage" is distinguished
> from a transient per-reference failure (§4 D-04-01, §16 Q-1) and where candidate/finding rows are
> written relative to the canonical step boundaries (§4 D-04-05, §16 Q-2) — are resolved with
> recommended defaults. If either answer changes, only §9 and §11.2 change.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.x`; PHP CLI `8.5`; `composer.json` requires `^8.3` |
| HTTP / cache | `Illuminate\Support\Facades\Http`; default `CACHE_STORE=array` in tests, `database` in prod |
| `spatie/laravel-data` | `4.x` (`app/Data/`, `BaseData`, `#[MapName(SnakeCaseMapper::class)]`) |
| Pest / DB / queue | Pest `5`, in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (`phpunit.xml`) |
| Phase 03 seam | `RunsDocumentAnalysis` bound to `AnalysisPipeline`; `AnalysisStepRegistry` + `PipelineStep` contract; `AnalysisServiceProvider` is the only wiring point |
| Context | `App\Services\Analysis\AnalysisContext` exposes typed `extraction()` only; docblock explicitly reserves a typed `embeddings` accessor for Phase 04 |
| Progress | `App\Services\Analysis\AnalysisProgress` (`begin`/`enter`/`report`/`leave`/`complete`); floors/ceilings in `config/analysis.progress`; `report()` is meant for intra-step progress but no step calls it today |
| Failure mapping | `App\Services\Analysis\AnalysisFailureHandler::safeMessage()` already documents the Phase 04 branch it expects (`CrossrefUnavailableException` → `Validasi Crossref tidak tersedia. Coba lagi nanti.`) |
| Config | `config/scoring.php` exists with thresholds/weights/semantic toggle/year tolerance/local-venue keywords; `config/services.php` `crossref` block has `base_url`/`mailto`/`timeout`/`connect_timeout`/`rows`/`cache_ttl`; `.env.example` has the matching placeholders |
| DOI utility | `App\Services\Crossref\DoiNormalizer` (`normalize()` best-effort, `isValid()` strict shape) — reuse verbatim, do not fork |
| Inference client | `App\Services\Inference\InferenceClient::embeddings(list<string>): EmbeddingResultData` already batches by `services.inference.embedding_batch_size` and validates dimensions; `InferenceUnavailableException` (5xx/timeout/connection) vs `InferenceClientException` (4xx/contract) |
| Models | `ReferenceFinding` (unique per `researched_document_reference_id`), `ReferenceFindingCandidate` (`rank` unique per finding), `ResearchedDocumentReference` (`+locations`, `+finding`); `ResearchedDocument::references()` exists |
| FK constraint | `reference_findings.selected_candidate_id` is a real FK → `reference_finding_candidates.id` (circular with the candidate FK); it **must be nulled before candidates are replaced** |
| Extraction fixture | `tests/Fixtures/inference/extract.json` already carries the three product cases — a valid DOI (URL-prefixed), a no-DOI reference, and a malformed DOI (`doi:not-a-doi`) — so end-to-end tests exercise all three |
| Crossref fixtures | `tests/Fixtures/crossref/doi-found.json` only |
| Test harness | `Tests\Support\{AnalysisHarness,InferenceFake,StubPipelineStep,DocumentTree,Fixtures}`; `AnalysisHarness::fullSteps()` currently registers test doubles for `crossref_validation`/`embedding`/`scoring` |
| Missing for this phase | Crossref client/query/mapper; scoring engine; the three steps; verification context; finding/candidate writer; fixtures/fakes; the `AnalysisFailureHandler` Crossref branch |

Facts that constrain the implementation:

- The canonical step order is fixed (`AnalysisStep::ordered()`); the registry sorts registered
  steps and rejects duplicates. Phase 04 only appends to the provider list.
- `AnalysisPipeline` owns progress floors/ceilings and failure handling; steps never catch fatal
  errors. A thrown `CrossrefUnavailableException` reaches `AnalysisFailureHandler`.
- `DocumentAnalysisResetService::reset()` deletes findings **and** candidates before a re-run, and
  `PersistExtractionStep` calls it inside its transaction; references are re-created with new ids,
  so findings cannot be stale across runs.
- `QUEUE_CONNECTION=sync` means integration tests run the pipeline inline; tests either call
  `AnalysisPipeline::run()` directly or rely on `AnalyzeDocumentJob::dispatchSync()`.
- The inference service is not implemented (OQ-13): all SBERT tests use `Http::fake()` payloads.

---

## 2. Execution model

### 2.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Crossref layer: value objects, `CrossrefClient`, query builder, mapper, `CrossrefUnavailableException`, crossref config | L | — | `feat(backend): add crossref client and query mapping` |
| **T2** | Scoring engine: `ScoringConfig`, `StringSimilarity`, `AuthorMatcher`, `SemanticSimilarity`, `LocalVenueDetector`, `MatchReasonBuilder`, `ReferenceScorer`, `VerdictDecider`, value objects | L | — | `feat(backend): add reference scoring engine` |
| **T3** | Pipeline primitives: `AnalysisContext` verification/embedding accessors, `AnalysisFailureHandler` Crossref branch, scoped `AnalysisProgress` binding, scoring config additions | M | — | `feat(backend): extend analysis context for crossref scoring` |
| **T4** | Steps + persistence: `ReferenceFindingWriter`, `ValidateReferencesStep`, `EmbedReferencesStep`, `ScoreReferencesStep`, provider wiring | L | T1, T2, T3 | `feat(backend): verify references with crossref and score candidates` |
| **T5** | Crossref fixtures + `CrossrefFake`/`InferenceFake` upgrades + full T-REF/T-SCORE/F-XREF matrix + pipeline/end-to-end test updates | L | T4 | `test(backend): cover crossref verification and scoring` |
| **T6** | Docs sync + full-suite exit validation | S | T1–T5 | `docs: sync crossref verification status` |

T1 and T2 are independent and can land in parallel. T3 is small and can also land in parallel. T4
is the first commit that makes a real document produce findings. T5 hardens with fixtures. No
migration is added.

### 2.2 Per-task loop

1. Re-read the task and the canonical `docs/API_SPEC.md` §2.6/§5/§10 section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Only T6 touches status docs; do not churn docs mid-phase.

### 2.3 Exit criteria (from `04-crossref-verification-and-scoring.md` §3/§5)

- T-REF-07, T-REF-08, T-SCORE-01..07, F-XREF-01..04, F-SCORE-01 and T-PIPE-03 pass.
- With `Http::fake()` Crossref + inference, `AnalysisEndToEndTest` drives the real
  `extract → persist → crossref_validation → embedding → scoring` sequence and persists exactly one
  finding per reference, ranked candidates, and the `selected_candidate_id`.
- All three product cases are observable from persisted state: (1) DOI resolving to a different
  publication → `invalid` with conflicting fields named; (2) DOI not found → `invalid` with
  `"DOI tidak ditemukan di Crossref."`; (3) valid reference without DOI → top candidate carries the
  suggested DOI and status `valid`.
- Candidate `rank` is 1..N, unique per finding, and matches score order; re-running scoring never
  violates the unique index.
- SBERT unavailability degrades to string signals, records the degradation, and still reaches
  `completed` (T-SCORE-03/OQ-04).
- No step after `crossref_validation` performs Crossref HTTP; scoring has no DB/HTTP/Eloquent
  dependency and is covered by pure unit tests.
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- No secret/`.env` value committed; `.env.example` gains placeholders only.
- No `docs/DB_SCHEMA.md` change; `docs/API_SPEC.md` gains only the OQ-02/OQ-11 clarification notes
  (no shape change).

---

## 3. Conventions this phase locks in

Reused from Phases 01–03 (do not re-implement):

- Responses only through `ApiResponse`/`ApiError`; pipeline code never touches the HTTP layer.
- All document state writes go through `DocumentAnalysisStateService`; all derived-row deletion goes
  through `DocumentAnalysisResetService`.
- One business rule per class; steps are idempotent and transactional per step.
- User-facing `analysis_error` strings are safe constants owned by `AnalysisFailureHandler`; raw
  exceptions go to the log only.
- Thresholds/weights/timeouts/limits are configuration, not literals in step code.
- `final class` + constructor property promotion + explicit types; PHPDoc over inline comments;
  `snake_case` enum values at the boundary.
- `DoiNormalizer` is the only DOI canonicalizer.

Phase-04-specific conventions:

- **Network and decision are separated.** `ValidateReferencesStep` performs Crossref I/O and nothing
  else; `ScoreReferencesStep` decides and persists. The scorer is a pure function of value objects
  and never resolves the container.
- **Transient inter-step data travels on `AnalysisContext`.** The verification batch and the
  embedding index live there; nothing new is persisted between `crossref_validation` and `scoring`.
- **Degradation is explicit, never silent.** A missing signal is `null` (weight redistributed), not
  `0`; an unavailable embedder sets a flag that appears in the finding `reason`.
- **All user-facing reason strings are built in `MatchReasonBuilder`** (finding reason) and
  `MatchReasonBuilder` / `VerdictDecider` (candidate `match_reason`) — one place to translate/tune.
- **No second normalizer, no second threshold map, no second candidate ranker.**

---

## 4. Decision log additions

These extend the README `Decision log` and the Phase 03 additions. They are internal implementation
decisions, not API/schema changes; each is recorded so later phases do not re-litigate it.

| ID | Question | Decision |
|---|---|---|
| **D-04-01** | How is a *total Crossref outage* (OQ-03 → document `failed`) distinguished from a *per-reference transient failure* (OQ-03 → reference `pending`)? | Track `$receivedAnyResponse` in `ValidateReferencesStep`, set to `true` whenever a Crossref exchange **completes** (a 2xx list/hit **or** a 404 miss — both prove reachability). A `CrossrefUnavailableException` (connection error or 5xx after retries) with `$receivedAnyResponse === false` is rethrown (fatal, document fails); one after a completed exchange degrades that reference to `pending`. This matches OQ-03 exactly ("connection error/5xx after retries" = outage). Deterministic, needs no new config, and aborts a real outage on the first reference instead of hammering Crossref N times. The threshold/circuit-breaker alternative is recorded in §16 Q-1. |
| **D-04-02** | Should a non-resolving, malformed or conflicting DOI trigger a bibliographic search so the correct publication can be suggested? | Yes, **best-effort**: whenever the reference has searchable bibliographic data (title or first author) a `/works` search is performed in addition to the DOI lookup. Candidates are de-duplicated by normalized DOI (then normalized title). This directly supports product case 1 ("DOI points elsewhere → show the right work") and keeps network I/O in the validation step. Cost is bounded by `services.crossref.rows`; see §13.4 for the scaling path. |
| **D-04-03** | Where is the DOI-resolved candidate ranked when its metadata conflicts? | It is ranked by its **actual** score (no forced rank 1). When the DOI matches and metadata agrees, the candidate's score is floored to `max(final, valid_threshold + ε)` so it short-circuits to rank 1. A conflicting DOI keeps a low score and can be outranked by a bibliographic candidate, which is then `selected_candidate_id` (the suggested correct work). |
| **D-04-04** | Finding `confidence` for an `invalid` DOI-conflict verdict. | `confidence` records the **evidence for the verdict**: for a conflicting DOI it is the DOI candidate's agreement score (low); for no-DOI cases it is the selected candidate's score; for a valid DOI it is the agreement score; for `not_found`/`pending` it is `null`. This keeps "high confidence + `invalid`" from reading as a contradiction. |
| **D-04-05** | Which step writes `reference_findings`/`reference_finding_candidates`? | `ScoreReferencesStep`, through `ReferenceFindingWriter`, after scoring. `ValidateReferencesStep` performs I/O only and puts a `ReferenceVerificationBatch` on the context; `EmbedReferencesStep` adds the `EmbeddingIndex`. This avoids a placeholder finding + a second write in `scoring`, keeps the decision in one class, and holds exactly one transaction. (Broad-plan deviation; the DB end state is identical.) |
| **D-04-06** | How does a step report intra-step progress, given `PipelineStep::handle()` receives no `AnalysisProgress`? | Bind `AnalysisProgress` with `$this->app->scoped()` in `AnalysisServiceProvider`. Queue workers call `forgetScopedInstances()` after every job, so the pipeline and its steps share **one** instance per run, and `AnalysisProgress::begin()` resets the write cache each run. Steps that loop (`ValidateReferencesStep`, `ScoreReferencesStep`) inject and call `report()`. The `PipelineStep` interface is unchanged. |
| **D-04-07** | Cache DOI misses, not just hits? | Yes. `Cache::remember("crossref:doi:{doi}", ttl, ...)` stores `['found' => bool, 'message' => array|null]`. Caching a 404 bounds repeated lookups on retry/duplicate references. Bibliographic searches are **not** cached (keeps evaluation reproducible, per the broad plan). |
| **D-04-08** | Crossref retry policy. | Bounded retries on connection errors and 5xx only, with exponential backoff; `404` is a result (not an exception and not retried); other 4xx are unexpected and map to `CrossrefUnavailableException`. Implemented with `Http::retry($times, $sleep, $when)` + `throw: true`, catching `RequestException`/`ConnectionException`. Configurable via `services.crossref.retries`/`retry_backoff_ms`. |
| **D-04-09** | Language of reason strings. | Finding `reason` strings are Indonesian (matching every `docs/API_SPEC.md` §5 example). Candidate `match_reason` is built by `MatchReasonBuilder` with a deterministic component-evidence format; the §5 example is English, so the plan uses Indonesian for user-facing consistency and centralizes it (§16 Q-4). |
| **D-04-10** | Missing signals (no title, no author, no year) | A signal is `null` when either side lacks the input; weights are renormalized over available signals instead of treating the signal as `0`. If **no** weighted signal is available, `final = 0.0` and the candidate cannot be `valid`. |
| **D-04-11** | Where do scoring value objects live? | Under `App\Services\Scoring\` next to the services (matching the broad plan's `CrossrefWorkData` placement), **not** in `app/Data/` — they are internal domain values, not API DTOs. `app/Data/` stays reserved for API/transport shapes. |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all existing tests pass.
- [ ] `git status` clean / expected branch (`feat/crossref-verification`).
- [ ] Re-read `04-crossref-verification-and-scoring.md` §2–§7 and the README `Decision log`; no open
      question blocks T1–T5 (the §16 questions have documented defaults).
- [ ] Confirm no `.env`/secret is read into code or docs.
- [ ] Confirm `reference_findings` has the OQ-14 unique index (migration
      `2026_10_06_071141_add_unique_index_to_reference_findings_table.php`) and that
      `selected_candidate_id` is a real FK.
- [ ] Confirm no `/references`, `/citations`, `/findings` endpoints exist yet (Phase 05 owns them).

No commit for T0.

---

## 6. T1 — Crossref layer

**New files:**
`app/Services/Crossref/{CrossrefClient,CrossrefQueryBuilder,CrossrefResultMapper,ReferenceQuery,CrossrefWorkData,DoiLookup}.php`,
`app/Exceptions/CrossrefUnavailableException.php`
**Modified:** `config/services.php`, `.env.example`
**Tests:** `tests/Feature/CrossrefClientTest.php`, `tests/Unit/CrossrefResultMapperTest.php`,
`tests/Unit/CrossrefQueryBuilderTest.php`

### 6.1 `CrossrefWorkData` (value object)

```php
namespace App\Services\Crossref;

final class CrossrefWorkData
{
    /** @param list<string> $authors one "Given Family" string per author */
    public function __construct(
        public readonly ?string $doi,
        public readonly ?string $title,
        public readonly array $authors = [],
        public readonly ?string $containerTitle = null,
        public readonly ?int $publicationYear = null,
        public readonly ?string $url = null,
        public readonly ?string $type = null,
    ) {}

    public function authorString(): ?string;
    public function hasBibliographicData(): bool; // title or authors present
}
```

A pure value object: no Eloquent, no container. `doi` is already normalized (lowercase, no URL
prefix) by the mapper via `DoiNormalizer`.

### 6.2 `ReferenceQuery` (value object) + `CrossrefQueryBuilder`

```php
final class ReferenceQuery
{
    public function __construct(
        public readonly string $bibliographic, // "title author year" composite
        public readonly ?string $author = null,
        public readonly ?int $year = null,
    ) {}

    public static function fromReference(ResearchedDocumentReference $reference): self;
}

final class CrossrefQueryBuilder
{
    /** @return array<string, string|int> Crossref `/works` query params */
    public function searchParams(ReferenceQuery $query, int $rows, ?string $mailto): array;

    /** `/works/{prefix}/{suffix}` path; the registrar slash stays a path separator. */
    public function doiPath(string $normalizedDoi): string;
}
```

`searchParams` emits:

| Param | Value |
|---|---|
| `query.bibliographic` | `trim("{$title} {$firstAuthorSurname} {$year}")`, collapsed whitespace, non-empty |
| `query.author` | first author surname (omitted when absent) |
| `rows` | `services.crossref.rows` |
| `select` | `DOI,title,author,container-title,issued,URL,type` |
| `mailto` | `services.crossref.mailto` (omitted when empty) |

`doiPath` returns `works/{prefix}/{rawurlencode($suffix)}` (split once on the first `/`): the
registrar slash must stay a **path separator** (`/works/10.1038/nature14539`), while the suffix is
URL-encoded so `?`, `#`, spaces and non-ASCII characters survive. Encoding the whole DOI would turn
the slash into `%2F`, which Crossref need not accept.

### 6.3 `CrossrefResultMapper`

Tolerant mapping from the Crossref JSON to `CrossrefWorkData` (`docs/API_SPEC.md` §10 shows only the
part of the Crossref shape we depend on):

| Crossref field | Mapping |
|---|---|
| `message.DOI` | `DoiNormalizer::normalize()` |
| `message.title` | first non-empty string |
| `message.author[]` | `trim(given . ' ' . family)`; skip empty; preserve order |
| `message.container-title` | first non-empty string |
| `message.issued.date-parts[0][0]` | int, when numeric and plausible |
| `message.URL` | string |
| `message.type` | string |

```php
final class CrossrefResultMapper
{
    /** `/works/{doi}` body → one work (or null when unusable). */
    public function mapSingle(array $payload): ?CrossrefWorkData;

    /** `/works` body → list of works, skipping unusable rows. @return list<CrossrefWorkData> */
    public function mapList(array $payload): array;
}
```

A row is unusable when it has neither a DOI nor a title. Missing `title`/`issued`/`author` are
tolerated (the scorer treats them as missing signals — D-04-10); malformed structures never throw.

### 6.4 `CrossrefUnavailableException`

```php
namespace App\Exceptions;

final class CrossrefUnavailableException extends RuntimeException
{
    public static function serviceUnavailable(?Throwable $previous = null): self;
    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self;
}
```

The **safe** `analysis_error` literal is owned by `AnalysisFailureHandler` (T3), never by the
exception.

### 6.5 `CrossrefClient`

```php
namespace App\Services\Crossref;

final class CrossrefClient
{
    public function __construct(
        private readonly CrossrefQueryBuilder $queryBuilder,
        private readonly CrossrefResultMapper $mapper,
        private readonly DoiNormalizer $normalizer,
    ) {}

    /** `null` = Crossref 404 (DOI does not resolve); throws on outage. */
    public function findByDoi(string $normalizedDoi): ?CrossrefWorkData;

    /** @return list<CrossrefWorkData> */
    public function searchBibliographic(ReferenceQuery $query): array;
}
```

Behaviour:

- `pending()` builds a `PendingRequest` from `services.crossref.*`: base URL, connect/read
  timeouts, `acceptJson()`, a `User-Agent`, and `mailto` (query param, not header).
  `User-Agent` = `services.crossref.user_agent` when set, otherwise
  `sprintf('%s/1.0 (mailto:%s)', config('app.name'), $mailto)` with the mailto clause omitted when
  empty.
- `findByDoi` caches via `Cache::remember("crossref:doi:{$doi}", $ttl, fn () => ...)` storing
  `['found' => bool, 'message' => array|null]` (D-04-07). The raw `message` array is cached; mapping
  happens after cache retrieval so a mapper change does not require a cache flush.
- `searchBibliographic` is never cached.
- Request execution (shared private `request()`), D-04-08:

```php
try {
    $response = $this->pending()->retry(
        $times = max(0, (int) config('services.crossref.retries', 2)),
        fn (int $attempt): int => (int) config('services.crossref.retry_backoff_ms', 200) * (2 ** ($attempt - 1)),
        fn (Throwable $e): bool => $e instanceof ConnectionException
            || ($e instanceof RequestException && $e->response->serverError()),
    )->get($path);
} catch (ConnectionException|RequestException $e) {
    if ($e instanceof RequestException && $e->response->status() === 404) {
        return null; // a result, not an outage
    }
    throw CrossrefUnavailableException::serviceUnavailable($e);
}
```

  `throw: true` (the default) is what lets the retry predicate see 5xx as exceptions; the `404`
  branch converts the only expected 4xx into a result. Any other status (e.g. `400`) maps to
  `CrossrefUnavailableException::unexpectedResponse($status)`.
- The client never logs response bodies and never decides a verdict.

### 6.6 Config additions

`config/services.php` `crossref`:

```php
'retries' => (int) env('CROSSREF_RETRIES', 2),
'retry_backoff_ms' => (int) env('CROSSREF_RETRY_BACKOFF_MS', 200),
'user_agent' => env('CROSSREF_USER_AGENT'),
```

`.env.example` (placeholders only):

```dotenv
# CROSSREF_RETRIES=2
# CROSSREF_RETRY_BACKOFF_MS=200
# CROSSREF_USER_AGENT=
```

### 6.7 Tests

`tests/Unit/CrossrefResultMapperTest.php`:

- `Fixtures::json('crossref/doi-found')` → DOI/`title`/`authors`/`containerTitle`/year/url mapped.
- A payload with a missing `title`, `issued`, and `container-title` maps to `null` fields (no throw).
- `mapList` skips a row with neither DOI nor title and keeps the rest.
- A JSON-string `issued.date-parts` and a numeric `issued.timestamp` are tolerated without throwing.

`tests/Unit/CrossrefQueryBuilderTest.php`:

- `searchParams` includes `query.bibliographic`, `rows`, `select`, and `mailto` only when configured.
- `query.author` is omitted when the reference has no parsed author.
- `doiPath` keeps the registrar slash as a path separator and URL-encodes only the suffix
  (`10.1038/nature14539` → `works/10.1038/nature14539`; a suffix with spaces is encoded).

`tests/Feature/CrossrefClientTest.php`:

- **F-XREF-04:** two `findByDoi` calls for the same DOI → `Http::assertSentCount(1)` (cache).
- DOI hit maps to `CrossrefWorkData` (fixture `crossref/doi-found`).
- DOI 404 → `null`; `CrossrefUnavailableException` is **not** thrown.
- 503 on the first attempt, 200 on the second → succeeds (retry works), bounded by config.
- 503 on all attempts → `CrossrefUnavailableException`.
- Connection error → `CrossrefUnavailableException`.
- `searchBibliographic` sends `mailto`, `rows`, `select`, and a `User-Agent`; maps a list fixture.
- A search 5xx → `CrossrefUnavailableException`; a search `400` → `CrossrefUnavailableException`.

---

## 7. T2 — Scoring engine (pure)

**New files:** `app/Services/Scoring/{ScoringConfig,StringSimilarity,AuthorMatcher,SemanticSimilarity,LocalVenueDetector,MatchReasonBuilder,ReferenceScorer,VerdictDecider,ScoreBreakdown,ScoredCandidate,ScoringReference,ReferenceVerdict}.php`
**Tests:** `tests/Unit/{StringSimilarityTest,AuthorMatcherTest,SemanticSimilarityTest,ReferenceScorerTest,VerdictDeciderTest,LocalVenueDetectorTest,MatchReasonBuilderTest}.php`

Every class in this task is pure: value objects in, value objects out. No Eloquent, no `Http`, no
`Cache`, no `config()` outside `ScoringConfig`.

### 7.1 `ScoringConfig`

```php
final class ScoringConfig
{
    public function __construct() {}
    public function validThreshold(): float;      // scoring.thresholds.valid
    public function suspiciousThreshold(): float; // scoring.thresholds.suspicious
    /** @return array{title: float, authors: float, journal: float, year: float} */
    public function weights(): array;             // sums to 1.0; validated
    public function yearTolerance(): int;
    public function semanticEnabled(): bool;
    public function semanticTitleBlend(): float;  // weight of semantic inside the title signal
    /** @return list<string> */
    public function localVenueKeywords(): array;
}
```

`weights()` throws `InvalidArgumentException` when the configured weights do not sum to 1.0 (±0.001)
so a misconfiguration fails at the first run.

### 7.2 `StringSimilarity`

```php
final class StringSimilarity
{
    public function normalize(?string $value): string;                 // mb-aware, lowercase, strip punctuation/extra spaces
    public function levenshtein(?string $a, ?string $b): ?float;       // normalized 0..1, null when either input is empty
    public function jaroWinkler(?string $a, ?string $b): ?float;       // 0..1, null when either input is empty
}
```

- Both metrics operate on Unicode code points (`mb_str_split`/`preg_split('//u')`), never bytes —
  PHP's native `levenshtein()` is byte-based and wrong for diacritics (broad-plan risk).
- Normalized Levenshtein = `1 - distance / max(len(a), len(b))`, clamped to `0..1`.
- `null` for empty inputs so the caller can redistribute weight (D-04-10), not `0`.

### 7.3 `AuthorMatcher`

```php
final class AuthorMatcher
{
    public function __construct(private readonly StringSimilarity $strings) {}

    /** Order-insensitive best-pair similarity; null when either side has no author. */
    public function similarity(?string $referenceAuthors, array $candidateAuthors): ?float;

    /** @return list<string> parsed/truncated surnames */
    public function surnames(?string $authors): array;
}
```

Parsing rules (conservative, APA/IEEE proposal scope):

- split on `&`, `and`, `;`, newlines, and the sequence `, ` when it separates people;
- strip trailing `et al.`/`dkk.`, parenthetical years, initials and punctuation;
- keep a canonical surname token per author;
- truncated candidate lists are compared order-insensitively (best-pair Jaro-Winkler averaged over
  the smaller set), with a mild penalty when author counts differ materially.

The exact heuristic lives in one place and is covered by `AuthorMatcherTest` with the required
`Koten` ↔ `Koton` case (T-SCORE-02).

### 7.4 `SemanticSimilarity`

```php
final class SemanticSimilarity
{
    /** Cosine similarity of two vectors; null when either is missing/empty/zero/mismatched. */
    public function cosine(?array $a, ?array $b): ?float;
}
```

Mismatched dimensions, zero vectors or empty arrays return `null` (signal unavailable), never a
fabricated value.

### 7.5 `ScoreBreakdown` / `ScoredCandidate` / `ScoringReference`

```php
final class ScoreBreakdown
{
    /**
     * @param array{title: ?float, authors: ?float, journal: ?float, year: ?float} $signals
     * @param list<string> $conflicts field names that disagreed (title/authors/journal/year)
     */
    public function __construct(
        public readonly array $signals,
        public readonly float $final,
        public readonly bool $doiMatch,
        public readonly bool $semanticUsed,
        public readonly bool $semanticDegraded,
        public readonly array $conflicts = [],
    ) {}
}

final class ScoredCandidate
{
    public function __construct(
        public readonly CrossrefWorkData $work,
        public readonly ScoreBreakdown $breakdown,
        public readonly int $rank,
        public readonly string $matchReason,
    ) {}

    public function confidence(): float;
}

final class ScoringReference
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $authors,
        public readonly ?string $publicationName,
        public readonly ?int $publicationYear,
        public readonly ?string $doi,          // normalized
        public readonly bool $doiValid,        // DoiNormalizer::isValid
    ) {}

    public static function fromModel(ResearchedDocumentReference $reference, DoiNormalizer $normalizer): self;
}
```

`ScoringReference::fromModel` is the **only** place the scorer touches a model; it immediately
converts to a pure value object.

### 7.6 `EmbeddingIndex`

```php
namespace App\Services\Analysis;

final class EmbeddingIndex
{
    /** @param array<string, list<float>> $vectors */
    private function __construct(private readonly array $vectors, private readonly bool $available) {}

    public static function fromVectors(array $vectors): self;
    public static function unavailable(): self;   // degraded, semantic signals null
    public static function empty(): self;         // available, nothing to embed

    public function isAvailable(): bool;
    public function hasVectors(): bool;
    public function vectorFor(string $key): ?array;

    /** Cosine between two stored vectors, or null when either key is missing. */
    public function similarity(string $leftKey, string $rightKey): ?float;

    public static function referenceKey(string $referenceId): string;             // "r:{id}"
    public static function candidateKey(string $referenceId, int $index): string; // "r:{id}:c:{index}"
}
```

The index is the transient artifact produced by `EmbedReferencesStep` and consumed by
`ScoreReferencesStep`. It lives in `App\Services\Analysis\` because it is a pipeline artifact, not a
scoring algorithm.

### 7.7 `ReferenceScorer`

```php
final class ReferenceScorer
{
    public function __construct(
        private readonly ScoringConfig $config,
        private readonly StringSimilarity $strings,
        private readonly AuthorMatcher $authors,
        private readonly SemanticSimilarity $semantic,
        private readonly MatchReasonBuilder $reasons,
    ) {}

    /**
     * @param  list<CrossrefWorkData>  $candidates
     * @return list<ScoredCandidate> ranked best-first, rank = 1..N
     */
    public function score(
        ScoringReference $reference,
        array $candidates,
        EmbeddingIndex $embeddings,
    ): array;
}
```

Per candidate:

| Signal | Computation | Notes |
|---|---|---|
| `title` | `blend(string, semantic)` when semantic available, else normalized Levenshtein | `blend = blend * semantic + (1 - blend) * string`; `semantic = cosine(refTitle, candTitle)` |
| `authors` | `AuthorMatcher::similarity()` | Jaro-Winkler, order-insensitive |
| `journal` | normalized Levenshtein of publication names | abbreviation-tolerant enough for v1 |
| `year` | `1.0` within `year_tolerance`, `0.0` outside, `null` when either year missing | exact match plus tolerance |
| `doiMatch` | candidate DOI === reference DOI (both normalized) | boolean, not weighted |

- Weighted final: `Σ wᵢ·sᵢ / Σ wᵢ` over **available** signals (D-04-10). No available signal → `0.0`.
- `semanticDegraded = !$embeddings->isAvailable() && $config->semanticEnabled()`.
- `conflicts` lists fields whose signal fell below a conflict floor (`title < 0.50`,
  `authors < 0.50`, `journal < 0.50`, `year === 0.0`).
- DOI short-circuit (D-04-03): when `doiMatch` and `conflicts === []`, set
  `final = max(final, validThreshold + 0.0001)` (capped at `1.0`) so the matched candidate ranks
  first.
- `match_reason` is produced by `MatchReasonBuilder` from the breakdown (component evidence).

Ranking: sort by `final` DESC, then `doiMatch` DESC, then `title` DESC, then stable input order;
assign `rank = 1..N`.

### 7.8 `LocalVenueDetector`

```php
final class LocalVenueDetector
{
    public function __construct(private readonly ScoringConfig $config) {}

    public function looksLocal(ScoringReference $reference, ?ScoredCandidate $best): bool;
}
```

Case-insensitive, mb-safe substring match of the configured keywords against the reference's
publication name and, when present, the best candidate's `containerTitle`. Empty keyword list (the
shipped default) → always `false`; the detector must never invent a match.

### 7.9 `MatchReasonBuilder`

```php
final class MatchReasonBuilder
{
    public function forCandidate(ScoringReference $reference, ScoredCandidate|ScoreBreakdown $breakdown): string;
    public function forFinding(ReferenceFindingStatus $status, ScoreBreakdown $breakdown, array $conflicts, bool $degraded, ?string $periodical): string;
}
```

- Candidate `match_reason` is deterministic and cites the component evidence, e.g.
  `"Kemiripan judul 0.78; penulis cocok 0.91; tahun cocok."` (D-04-09).
- Finding `reason` templates (Indonesian, provisional, centralized):
  - valid DOI + agreement → `"DOI cocok dengan metadata Crossref."`
  - DOI conflict → `"DOI menunjuk ke publikasi yang berbeda. Perbedaan: judul, tahun."`
  - DOI not found → `"DOI tidak ditemukan di Crossref."` (OQ-02, exact literal)
  - DOI malformed → `"Format DOI tidak valid."`
  - no DOI, valid → `"Kandidat dengan DOI {doi} memiliki kemiripan tinggi."` /
    `"Kandidat terbaik memiliki kemiripan tinggi."` when the candidate has no DOI
  - no DOI, suspicious → `"Judul pada metadata Crossref memiliki perbedaan."`
  - not found → `"Tidak ada kandidat ditemukan di Crossref."`
  - transient → `"Validasi Crossref gagal sementara. Coba lagi nanti."`
  - degraded semantic → append `" Kemiripan semantik tidak tersedia."`

### 7.10 `VerdictDecider`

```php
final class VerdictDecider
{
    public function __construct(
        private readonly ScoringConfig $config,
        private readonly LocalVenueDetector $localVenue,
    ) {}

    /**
     * @param  list<ScoredCandidate>  $ranked  best-first
     */
    public function decide(
        ScoringReference $reference,
        DoiLookup $doiLookup,
        bool $transientFailure,
        array $ranked,
    ): ReferenceVerdict;
}
```

Decision matrix (the single source of truth — `docs/API_SPEC.md` §2.6 + OQ-02):

```text
1. transientFailure                       → pending,  confidence null, no selection
2. DOI malformed                          → invalid,  confidence null, reason "Format DOI tidak valid."
3. DOI 404                                → invalid,  confidence null, reason "DOI tidak ditemukan di Crossref."
4. DOI 200 and no conflicts               → valid,    confidence = DOI candidate agreement,
                                            selected = DOI candidate
5. DOI 200 and conflicts                  → invalid,  confidence = DOI candidate agreement,
                                            reason names conflicting fields,
                                            selected = best-ranked candidate overall (may be a
                                            bibliographic match = suggested correct work)
6. no DOI, ranked empty                   → not_found   (or suspicious when the venue looks local)
7. no DOI, ranked[0].confidence >= valid  → valid,    confidence = ranked[0], selected = ranked[0]
                                            (suggested DOI = ranked[0] DOI when present — FR-R7)
8. no DOI, ranked[0].confidence >= susp.  → suspicious, selected = ranked[0]
9. no DOI, below suspicious               → suspicious when the venue looks local, else not_found
```

Rules 6–9 keep every candidate "available but weak" as `suspicious` (never `not_found`, which means
"Crossref returned nothing") and reserve `not_found` for an empty candidate set, satisfying
T-SCORE-06 and the proposal's false-positive guidance. `confidence` is rounded to 4 decimals and
clamped to `[0,1]`.

### 7.11 `ReferenceVerdict`

```php
final class ReferenceVerdict
{
    /** @param list<ScoredCandidate> $candidates ranked, rank = 1..N */
    public function __construct(
        public readonly string $referenceId,
        public readonly ReferenceFindingStatus $status,
        public readonly ?float $confidence,
        public readonly ?string $reason,
        public readonly ?int $selectedRank,   // 1..N, or null
        public readonly array $candidates,
    ) {}
}
```

Using `selectedRank` (not a pre-generated UUID) lets `ReferenceFindingWriter` assign UUIDs during
insert and point `selected_candidate_id` at the inserted row.

### 7.12 Tests

`StringSimilarityTest`:
- **T-SCORE-01:** known pairs (e.g. `kitten`/`sitting`, `Nature`/`Natur`, `""`/`x` → `null`) produce
  expected normalized Levenshtein values.
- Unicode/diacritic pairs (`Koten`/`Kotén`, `Müller`/`Mueller`) do not throw and score reasonably.
- **T-SCORE-02:** `Koten` vs `Koton` Jaro-Winkler is high (e.g. `> 0.85`) — the required author case.
- Empty inputs return `null`, not `0`.

`AuthorMatcherTest`:
- `"LeCun, Y., Bengio, Y., & Hinton, G."` vs `['Yann LeCun', 'Yoshua Bengio', 'Geoffrey Hinton']`
  is high.
- Order-insensitivity: reversed candidate order yields the same score.
- `et al.` truncation and a missing author on one side behave as documented.

`SemanticSimilarityTest`:
- Identical vectors → `1.0`; orthogonal → `0.0`; opposite → `-1.0` (clamped or left as-is per
  implementation, documented in the test).
- `null`/empty/zero/mismatched-dimension inputs → `null`.

`ReferenceScorerTest`:
- **T-SCORE-04:** the higher-confidence candidate ranks first.
- **T-SCORE-05:** a candidate exactly at `valid` threshold and one just below produce the expected
  `conflicts`/`final`.
- Missing signals redistribute weight (a reference with no year still scores on title/authors).
- DOI match with no conflicts floors `final` above the valid threshold; DOI match with a conflicting
  title does **not**.
- Semantic unavailable sets `semanticDegraded` and uses string title similarity.

`VerdictDeciderTest`:
- Every row of the §7.10 matrix, including T-SCORE-06 (local-venue low score → `suspicious`).
- DOI 404 → `invalid` + `"DOI tidak ditemukan di Crossref."`.
- DOI conflict → `invalid` + a reason that names the conflicting fields, selected = best candidate.
- FR-R7: no DOI + best candidate ≥ valid → `valid` and `selectedRank = 1`.

`LocalVenueDetectorTest`:
- Empty keyword list → `false`; a configured keyword present in the reference or candidate venue →
  `true`; case-insensitive/Unicode-tolerant.

`MatchReasonBuilderTest`:
- Deterministic strings for each status; degraded reason appends the semantic note; conflicting
  fields are named.

---

## 8. T3 — Pipeline primitives

**Modified:** `app/Services/Analysis/AnalysisContext.php`,
`app/Services/Analysis/AnalysisFailureHandler.php`, `app/Providers/AnalysisServiceProvider.php`,
`config/scoring.php`, `.env.example`
**Tests:** extend `tests/Feature/AnalysisFailureHandlerTest.php`, `tests/Unit/ScoringConfigTest.php`

### 8.1 `AnalysisContext` accessors

Add two typed artifacts (mirroring the existing extraction accessors — no generic bag):

```php
private ?ReferenceVerificationBatch $verification = null;
private ?EmbeddingIndex $embeddings = null;

public function setVerification(ReferenceVerificationBatch $verification): void;
public function hasVerification(): bool;
public function verification(): ReferenceVerificationBatch;          // throws LogicException
public function takeVerification(): ReferenceVerificationBatch;      // consume + clear

public function setEmbeddings(EmbeddingIndex $embeddings): void;
public function hasEmbeddings(): bool;
public function embeddings(): EmbeddingIndex;                        // throws LogicException
```

`verification` is set by `ValidateReferencesStep` and consumed by `EmbedReferencesStep` (peek) and
`ScoreReferencesStep` (take). `embeddings` is set by `EmbedReferencesStep` and consumed by
`ScoreReferencesStep`.

### 8.2 `AnalysisFailureHandler` Crossref branch

Add to `safeMessage()`:

```php
$exception instanceof CrossrefUnavailableException => 'Validasi Crossref tidak tersedia. Coba lagi nanti.',
```

This is the literal the Phase 03 hand-off reserved. `CrossrefUnavailableException` therefore fails
the document (OQ-03 total-outage path).

### 8.3 Scoped `AnalysisProgress` binding (D-04-06)

In `AnalysisServiceProvider::register()`:

```php
$this->app->scoped(AnalysisProgress::class);
```

Keep the pipeline transient. Because `AnalysisProgress::begin()` resets `$lastWritten`, a shared
per-job instance is safe; queue workers flush scoped instances after every job, and tests that call
`AnalysisPipeline::run()` directly still reset at `begin()`.

### 8.4 Scoring config additions

`config/scoring.php` gains:

```php
// Weight of the SBERT semantic term inside the title signal (rest is string similarity).
'semantic' => [
    'enabled' => (bool) env('SCORING_SEMANTIC_ENABLED', true),
    'title_blend' => (float) env('SCORING_SEMANTIC_TITLE_BLEND', 0.6),
],
```

`.env.example` adds `# SCORING_SEMANTIC_TITLE_BLEND=0.6`. Existing keys (`thresholds`, `weights`,
`year_tolerance`, `local_venue_keywords`) stay; no rename.

### 8.5 Tests

- `AnalysisContext`: setting/peeking/taking verification and embeddings; a missing artifact throws
  `LogicException`; `takeVerification()` clears.
- `AnalysisFailureHandler`: a `CrossrefUnavailableException` maps to the Crossref literal and marks
  the document `failed`; the literal cannot drift from the exception's semantics.
- `ScoringConfig`: weights must sum to 1.0; a bad config throws; defaults load.

---

## 9. T4 — Steps and finding persistence

**New files:** `app/Services/Analysis/{ReferenceVerification,ReferenceVerificationBatch}.php`,
`app/Services/Analysis/Steps/{ValidateReferencesStep,EmbedReferencesStep,ScoreReferencesStep}.php`,
`app/Services/ReferenceFinding/ReferenceFindingWriter.php`
**Modified:** `app/Providers/AnalysisServiceProvider.php`
**Tests:** `tests/Feature/{ValidateReferencesStepTest,EmbedReferencesStepTest,ScoreReferencesStepTest,ReferenceFindingWriterTest}.php`

### 9.1 Verification value objects

```php
final class ReferenceVerification
{
    /** @param list<CrossrefWorkData> $candidates */
    public function __construct(
        public readonly ScoringReference $reference,
        public readonly DoiLookup $doiLookup,
        public readonly array $candidates,
        public readonly bool $transientFailure = false,
    ) {}
}

final class ReferenceVerificationBatch
{
    /** @param list<ReferenceVerification> $verifications */
    public function __construct(public readonly array $verifications) {}
    public function isEmpty(): bool;
    public function count(): int;
}
```

`DoiLookup` (`app/Services/Crossref/DoiLookup.php`): `NotPresent | Malformed | Resolved | NotFound`.

### 9.2 `ValidateReferencesStep` (`crossref_validation`)

```php
final class ValidateReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly CrossrefClient $client,
        private readonly DoiNormalizer $normalizer,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep { return AnalysisStep::CrossrefValidation; }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

Algorithm:

```text
1. references = $document->references()
        ->orderByRaw('text_start_offset IS NULL')
        ->orderBy('text_start_offset')->orderBy('id')->get()
   (deterministic; matches the OQ-18 bibliography order used by Phase 05)
2. $receivedAnyResponse = false
3. for each reference (index i, total N):
     reference Vo = ScoringReference::fromModel($reference, $normalizer)
     $doi = $normalizer->normalize($reference->doi)
     candidates = []
     doiLookup = NotPresent
     if ($doi !== null):
        if (!$normalizer->isValid($doi)):
            doiLookup = Malformed
        else:
            try:
                $work = $client->findByDoi($doi)
                $receivedAnyResponse = true            // hit or 404 both prove reachability
                if ($work === null) doiLookup = NotFound
                else { doiLookup = Resolved; candidates[] = $work }
            catch (CrossrefUnavailableException $e):
                if (!$receivedAnyResponse) throw $e     // D-04-01: total outage
                verifications[] = ReferenceVerification(... transientFailure: true)
                progress->report(...); continue
     // D-04-02: search whenever there is bibliographic data to search on
     if ($reference->title !== null || $reference->authors !== null):
        try:
            search = $client->searchBibliographic(ReferenceQuery::fromReference($reference))
            $receivedAnyResponse = true                    // a 2xx list proves reachability
            candidates = merge(candidates, search)         // de-dupe by normalized DOI, then title
        catch (CrossrefUnavailableException $e):
            if (!$receivedAnyResponse) throw $e
            verifications[] = ... transientFailure: true
            progress->report(...); continue
     verifications[] = new ReferenceVerification($referenceVo, $doiLookup, $candidates)
     $progress->report($document, $this->step(), $i + 1, $N)
4. $context->setVerification(new ReferenceVerificationBatch($verifications))
```

Notes:

- `transientFailure` sets `doiLookup` to whatever was determined before the failure or
  `NotPresent`; the decider prioritizes `transientFailure`.
- De-duplication keeps the DOI-lookup copy of a work (authoritative) and falls back to a normalized
  title comparison for DOI-less works.
- No writes and no transactions in this step. If it throws, the pipeline's failure handler maps
  `CrossrefUnavailableException` to the Crossref safe message.
- Progress spans `crossref_validation` (35→60); `report()` is bounded by `AnalysisProgress`.

### 9.3 `EmbedReferencesStep` (`embedding`)

```php
final class EmbedReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly InferenceClient $client,
        private readonly ScoringConfig $config,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep { return AnalysisStep::Embedding; }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

Algorithm:

```text
1. batch = $context->verification()
2. if (!$config->semanticEnabled()):
       $context->setEmbeddings(EmbeddingIndex::unavailable()); return
3. texts = []; keys = []
   for each verification (index i):
       if reference.title non-empty:        texts[] = title; keys[] = referenceKey(refId)
       for each candidate (index j):
           if work.title non-empty:         texts[] = title; keys[] = candidateKey(refId, j)
   (blank titles are skipped, never sent — InferenceClient rejects empty strings)
4. if (texts === []): $context->setEmbeddings(EmbeddingIndex::empty()); return
5. try:
       result = $client->embeddings($texts)
       $context->setEmbeddings(EmbeddingIndex::fromVectors(array_combine($keys, $result->vectors())))
   catch (InferenceUnavailableException $e):
       Log::warning('Embeddings unavailable; reference scoring degrades to string signals.', [
           'document_id' => $document->getKey(),
       ]);
       $context->setEmbeddings(EmbeddingIndex::unavailable())
   // InferenceClientException (4xx/contract) propagates → fatal (D-03-09 / OQ-04)
6. $progress->leave() is handled by the runner; no per-item reports needed (batched call)
```

`$client->embeddings()` already chunks by `services.inference.embedding_batch_size` and validates
that every vector matches the declared dimensions and that the count round-trips; the step relies on
that contract.

### 9.4 `ScoreReferencesStep` (`scoring`)

```php
final class ScoreReferencesStep implements PipelineStep
{
    public function __construct(
        private readonly ReferenceScorer $scorer,
        private readonly VerdictDecider $decider,
        private readonly ReferenceFindingWriter $writer,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep { return AnalysisStep::Scoring; }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

Algorithm:

```text
1. batch = $context->takeVerification()
   embeddings = $context->embeddings()
2. verdicts = []
   for each verification (index i, total N):
       ranked = $scorer->score($verification->reference, $verification->candidates, $embeddings)
       verdicts[] = $decider->decide(
           $verification->reference, $verification->doiLookup,
           $verification->transientFailure, $ranked,
       )
       $progress->report($document, $this->step(), $i + 1, $N)
3. $writer->persistBatch($document, $verdicts)
```

### 9.5 `ReferenceFindingWriter`

```php
namespace App\Services\ReferenceFinding;

final class ReferenceFindingWriter
{
    /**
     * Upsert one finding per reference and replace its ranked candidates in a
     * single transaction.
     *
     * @param  list<ReferenceVerdict>  $verdicts
     */
    public function persistBatch(ResearchedDocument $document, array $verdicts): void;
}
```

Per verdict, inside one `DB::transaction` for the whole document:

```text
1. finding = ReferenceFinding::firstOrNew(['researched_document_reference_id' => $referenceId])
2. // Circular FK: the finding may point at a candidate that is about to be deleted.
   finding->selected_candidate_id = null
   finding->fill([
       'researched_document_id' => $document->getKey(),
       'status'       => $verdict->status,
       'confidence'   => $verdict->confidence,   // round(4), clamped [0,1]
       'reason'       => $verdict->reason,
   ]);
   // is_manual/reviewed_by/reviewed_at are untouched for an automated run.
   finding->save()
3. finding->candidates()->delete()
4. $ids = []
   foreach ($verdict->candidates as $candidate):
       $id = (string) Str::uuid();
       ReferenceFindingCandidate::create([
           'id' => $id,
           'reference_finding_id' => $finding->getKey(),
           'rank' => $candidate->rank,                 // 1..N, unique per finding
           'confidence' => round($candidate->confidence(), 4),
           'doi' => $candidate->work->doi,
           'title' => $candidate->work->title,
           'authors' => $candidate->work->authorString(),
           'publication_name' => $candidate->work->containerTitle,
           'publication_year' => $candidate->work->publicationYear,
           'url' => $candidate->work->url,
           'match_reason' => $candidate->matchReason,
       ]);
       $ids[$candidate->rank] = $id;
5. finding->selected_candidate_id = $verdict->selectedRank === null ? null : $ids[$verdict->selectedRank]
   finding->save()
```

Guarantees:

- `selected_candidate_id` is always nulled before candidates are replaced (the FK is real), and only
  ever points at a candidate of the same finding.
- `rank` is 1..N and matches `ScoredCandidate::rank`, so the unique `(reference_finding_id, rank)`
  index cannot be violated.
- Every candidate stores the DOI that can be suggested (FR-R7).
- Existing manual findings are not special-cased here because a re-run resets derived rows first
  (`DocumentAnalysisResetService`), including manual ones; the API-level manual flow is Phase 05.

### 9.6 Provider wiring

`AnalysisServiceProvider` appends the three steps in canonical order and binds the new services:

```php
$this->app->scoped(AnalysisProgress::class);
$this->app->singleton(ScoringConfig::class);

$this->app->singleton(AnalysisStepRegistry::class, fn (Application $app): AnalysisStepRegistry => new AnalysisStepRegistry([
    $app->make(ExtractDocumentStep::class),
    $app->make(PersistExtractionStep::class),
    $app->make(ValidateReferencesStep::class),   // crossref_validation
    $app->make(EmbedReferencesStep::class),      // embedding
    $app->make(ScoreReferencesStep::class),      // scoring
    // Phase 05 appends ResolveCitationsStep.
    $app->make(FinalizeAnalysisStep::class),
]));
```

`ReferenceScorer`, `VerdictDecider`, `StringSimilarity`, `AuthorMatcher`, `SemanticSimilarity`,
`LocalVenueDetector`, `MatchReasonBuilder`, `CrossrefClient`, `CrossrefQueryBuilder`,
`CrossrefResultMapper` and `ReferenceFindingWriter` are resolved by the container's autowiring.

### 9.7 Tests

`ValidateReferencesStepTest`:
- **T-XREF happy path:** with a fake, a valid-DOI reference is `Resolved` with the DOI candidate plus
  search candidates; a no-DOI reference is `NotPresent` with search candidates; a malformed DOI is
  `Malformed`; a 404 DOI is `NotFound`.
- **F-XREF-01:** DOI 404 → `doiLookup = NotFound` (the `invalid` verdict is asserted in
  `ScoreReferencesStepTest`/end-to-end).
- **F-XREF-02:** first request transport-fails → `CrossrefUnavailableException` escapes the step.
- **F-XREF-03:** a 404 first, then a transport failure → the failing reference is
  `transientFailure` and the rest still get verdicts; the step does not throw.
- De-duplication: a DOI work also returned by the search appears once.
- Deterministic reference order (offsets ASC, nulls last, id tiebreak).

`EmbedReferencesStepTest`:
- Batches texts; every reference + candidate title is embedded once (assert request count/body).
- **T-SCORE-03:** inference 503 → `EmbeddingIndex::unavailable()`, no exception, document continues.
- Inference 4xx (`InferenceClientException`) → fatal (propagates).
- `semantic.enabled = false` → no HTTP call, unavailable index.
- No titles → `EmbeddingIndex::empty()` and no HTTP call.

`ReferenceFindingWriterTest`:
- Upsert creates one finding per reference; a second call updates in place (no unique-index error).
- Candidate ranks are 1..N and `selected_candidate_id` points at the quoted rank.
- Replacing candidates while `selected_candidate_id` is set does not violate the FK (nulling).
- `confidence` rounding/clamping and `is_manual` untouched.
- Manual audit fields on an existing finding survive an automated write.

`ScoreReferencesStepTest`:
- **T-REF-07:** DOI resolving to a different publication → `invalid`, reason names fields, selected
  candidate is the best match.
- **T-REF-08:** valid reference without a DOI → `valid`, top candidate carries the suggested DOI.
- **T-SCORE-07:** re-running scoring on the same document leaves exactly N candidates per finding
  with ranks 1..N (no unique-index violation).
- **F-SCORE-01:** reason strings are deterministic for a fixed input.
- Transient verification → `pending` finding with the transient reason and no candidates.

---

## 10. T5 — Fixtures, fakes and the full acceptance matrix

**New files:** `tests/Fixtures/crossref/{doi-conflict,search-multiple,search-empty,malformed}.json`,
`tests/Support/CrossrefFake.php`
**Modified:** `tests/Support/InferenceFake.php`, `tests/Support/AnalysisHarness.php`,
`tests/Feature/AnalysisPipelineTest.php`, `tests/Feature/AnalysisEndToEndTest.php`
**Tests:** `tests/Feature/CrossrefVerificationEndToEndTest.php`

### 10.1 Crossref fixtures

- `doi-found.json` (exists) — resolves and agrees with the valid-DOI reference in
  `inference/extract.json`.
- `doi-conflict.json` — same DOI but a different title/author/year (product case 1).
- `search-multiple.json` — `/works` list with 2–3 items matching the no-DOI reference and one
  distractor.
- `search-empty.json` — `{"message": {"items": []}}`.
- `malformed.json` — a work missing `title`/`issued` (tolerance test).

### 10.2 `CrossrefFake`

```php
final class CrossrefFake
{
    public static function install(array $doiMap, array $searchMap): void;

    public static function forExtractFixture(): void;   // wires the three extract.json references
    public static function unavailable(): void;          // any crossref URL → 503
    public static function connectionError(): void;
}
```

`install()` registers one `Http::fake` closure for `config('services.crossref.base_url').'/*'` that
dispatches on the request:

- a `/works/{doi}` path → the registered DOI body (or `Http::response('', 404)`);
- a `/works?...` search → the registered search body (or `search-empty`).

`forExtractFixture()` registers `10.1038/nature14539` → `doi-found` and returns `search-multiple`
for the no-DOI/malformed searches, so the extraction fixture drives all three product cases.

### 10.3 `InferenceFake` upgrade

`InferenceFake::embeddings()` currently returns identical `0.5` vectors (cosine `1.0`), which cannot
test ranking. Add:

```php
public static function embeddings(int $dimensions = 3): void;              // existing behaviour
public static function embeddingsFromText(): void;                          // deterministic per-text vectors
public static function embeddingsFor(array $vectorsByText): void;           // exact control
```

`embeddingsFromText()` derives a small, stable vector from a hash of each text so identical titles
are nearest and unrelated titles are far apart — enough to exercise T-SCORE-04 without a real SBERT.

### 10.4 `AnalysisHarness::fullSteps()` upgrade

Replace the Phase 04 stubs with the real steps:

```php
return [
    app(ExtractDocumentStep::class),
    app(PersistExtractionStep::class),
    app(ValidateReferencesStep::class),
    app(EmbedReferencesStep::class),
    app(ScoreReferencesStep::class),
    StubPipelineStep::for(AnalysisStep::ResolvingCitations),   // Phase 05
    app(FinalizeAnalysisStep::class),
];
```

Existing Phase 03 tests that relied on a Crossref stub must now install a `CrossrefFake`; otherwise
`CrossrefClient` hits the base URL. Update `AnalysisPipelineTest` and `AnalysisEndToEndTest` to fake
both external services. Tests that only need ordering keep using `StubPipelineStep` explicitly.

### 10.5 Acceptance matrix (this phase's rows)

| ID | Case | Covered by |
|---|---|---|
| T-SCORE-01 | Normalized Levenshtein known pairs | `StringSimilarityTest` |
| T-SCORE-02 | Jaro-Winkler `Koten`/`Koton` | `StringSimilarityTest` / `AuthorMatcherTest` |
| T-SCORE-03 | SBERT unavailable → string signals, degradation recorded, document completes | `EmbedReferencesStepTest` + `AnalysisPipelineTest` |
| T-SCORE-04 | Higher-confidence candidate ranks first | `ReferenceScorerTest` + `ScoreReferencesStepTest` |
| T-SCORE-05 | Threshold boundary (≥ 0.85) | `ReferenceScorerTest` + `VerdictDeciderTest` |
| T-SCORE-06 | Local-journal low score → `suspicious` | `LocalVenueDetectorTest` + `VerdictDeciderTest` |
| T-SCORE-07 | Candidate ranks unique per finding under upsert | `ReferenceFindingWriterTest` + `ScoreReferencesStepTest` |
| T-REF-07 | DOI resolves to a different publication → `invalid` + reason | `ScoreReferencesStepTest` + e2e |
| T-REF-08 | Valid reference without DOI → top candidate carries suggested DOI | `ScoreReferencesStepTest` + e2e |
| F-XREF-01 | Crossref 404 → `invalid` + `"DOI tidak ditemukan di Crossref."` | `ValidateReferencesStepTest` + `VerdictDeciderTest` + e2e |
| F-XREF-02 | Total outage → document `failed` with the Crossref safe message | `ValidateReferencesStepTest` + `AnalysisPipelineTest` |
| F-XREF-03 | Per-reference transient failure → others still scored (T-PIPE-03) | `ValidateReferencesStepTest` + e2e |
| F-XREF-04 | DOI cache → second lookup does not hit HTTP | `CrossrefClientTest` |
| F-SCORE-01 | Reason strings deterministic, evidence-bearing | `MatchReasonBuilderTest` |

### 10.6 End-to-end test

`CrossrefVerificationEndToEndTest` / updated `AnalysisEndToEndTest`:

- `InferenceFake::extraction()` + `InferenceFake::embeddingsFromText()` + `CrossrefFake::forExtractFixture()`.
- Run the real pipeline through `AnalyzeDocumentJob::dispatchSync()`.
- Assert:
  - document `completed`, progress `100`, step `completed`;
  - `reference_findings` count = number of references, each unique per reference;
  - the valid DOI reference → `valid` with the DOI candidate selected;
  - the no-DOI reference → `valid` (or `suspicious`, per fixture) with a candidate carrying a
    suggested DOI (FR-R7);
  - the malformed-DOI reference → `invalid` with the format reason;
  - candidates' ranks are `1..N` and ordering matches confidence DESC;
  - no Crossref request is sent after the `crossref_validation` step (assert via `Http::recorded()`
    request count versus the fake's expected lookups).
- A second variant with `InferenceFake::unavailable()` asserts the document still completes and the
  reason records the semantic degradation (T-SCORE-03/OQ-04).
- A second variant with `CrossrefFake::unavailable()` asserts the document `failed` with
  `"Validasi Crossref tidak tersedia. Coba lagi nanti."` (F-XREF-02).

---

## 11. T6 — Documentation sync and final validation

- [ ] `docs/ARCHITECTURE.md` §13 — backend bullet: Crossref client/query/mapper, scoring engine,
      `ValidateReferencesStep`/`EmbedReferencesStep`/`ScoreReferencesStep`, `ReferenceFindingWriter`;
      update the "Missing" list (remove Crossref/scoring; keep citation resolution, endpoints,
      reports, inference service).
- [ ] `AGENTS.md` §14 — same status update (Crossref validation and scoring now exist; the missing
      list stays accurate).
- [ ] `docs/plans/backend/README.md` §3.1/§3.2 and §7/§8 — move Crossref/scoring from "missing" to
      "exists"; mark Phase 04 implemented in the phase map.
- [ ] `docs/plans/backend/04-crossref-verification-and-scoring.md` — status header points at this
      detailed plan.
- [ ] `docs/API_SPEC.md` — add the OQ-02 clarification note (a DOI present but not found in Crossref
      is `invalid` with `"DOI tidak ditemukan di Crossref."`; `not_found` only when no DOI and no
      candidate) and the OQ-11 note (no `CROSSREF_API_KEY`; `CROSSREF_MAILTO` + contact
      `User-Agent`). **No shape change** — if any task needs one, it is a contract change and must be
      called out (README §2/§9.6).
- [ ] Confirm **no** `docs/DB_SCHEMA.md` change (the OQ-14 unique index already exists).
- [ ] `php artisan test --compact` (full suite) + `vendor/bin/pint --dirty --format agent`.

---

## 12. Files touched (summary)

```text
backend/app/
├── Exceptions/                         +    CrossrefUnavailableException
├── Providers/AnalysisServiceProvider   ~    append 3 steps; scoped AnalysisProgress;
│                                            singleton ScoringConfig
├── Services/Analysis/AnalysisContext   ~    + verification/embeddings typed accessors
├── Services/Analysis/AnalysisFailureHandler ~ + Crossref safe message
├── Services/Analysis/                   +   EmbeddingIndex, ReferenceVerification,
│                                            ReferenceVerificationBatch
│   └── Steps/                           +   ValidateReferencesStep, EmbedReferencesStep,
│                                            ScoreReferencesStep
├── Services/Crossref/                   +   CrossrefClient, CrossrefQueryBuilder,
│                                            CrossrefResultMapper, ReferenceQuery,
│                                            CrossrefWorkData, DoiLookup
│                                            (reuses existing DoiNormalizer)
├── Services/ReferenceFinding/           +   ReferenceFindingWriter
└── Services/Scoring/                    +   ScoringConfig, StringSimilarity, AuthorMatcher,
                                             SemanticSimilarity, LocalVenueDetector,
                                             MatchReasonBuilder, ReferenceScorer,
                                             VerdictDecider, ScoreBreakdown, ScoredCandidate,
                                             ScoringReference, ReferenceVerdict

backend/config/services.php              ~    + crossref retries/backoff/user_agent
backend/config/scoring.php               ~    + semantic.title_blend
backend/.env.example                     ~    + CROSSREF_RETRIES, CROSSREF_RETRY_BACKOFF_MS,
                                               CROSSREF_USER_AGENT, SCORING_SEMANTIC_TITLE_BLEND
backend/tests/                           NEW crossref fixtures + CrossrefFake + unit/feature tests
```

No `routes/`, `Http/`, `app/Data/`, migration or DTO-envelope change. `DoiNormalizer` is untouched.

---

## 13. Risks, pitfalls and deliberate deviations

### 13.1 Pitfalls to avoid

1. **A placeholder finding written by `ValidateReferencesStep`.** D-04-05: validation does I/O only;
   the scoring step is the single writer, so the verdict is never computed twice.
2. **Deleting candidates before nulling `selected_candidate_id`.** The FK is real and circular; the
   writer must null it first or the replace fails.
3. **Byte-based similarity.** PHP's `levenshtein()` is byte-based; use the mb-safe `StringSimilarity`
   and test diacritics.
4. **Treating a missing signal as `0`.** That turns "no year" into a maximum penalty; renormalize
   instead (D-04-10).
5. **Letting SBERT alone decide.** `semantic` is one weighted term; the DOI/exact and string signals
   must be able to carry a verdict when embeddings are down.
6. **A second outage heuristic.** Keep the single `$receivedAnyResponse` rule (D-04-01); do not add
   per-reference counters or a second config knob without changing the decision log.
7. **Unbounded Crossref load.** Honour `rows`, cache DOI lookups (including misses), retry only
   connection/5xx, and keep one search per reference. Never run searches in the `scoring` step.
8. **Caching a mapped value object.** Cache the raw `message` array and map after retrieval so the
   mapping can evolve without a stale-cache bug.
9. **Non-deterministic reference order.** Iterate references ordered by `text_start_offset` (nulls
   last, `id` tiebreak) so verification, progress and Phase 05 resolution agree (OQ-18).
10. **Swallowing a Crossref outage.** A fatal outage must reach `AnalysisFailureHandler`, not become
    a document full of `pending` references.
11. **Scoped-instance mistakes.** `AnalysisProgress` must stay `scoped`, not `singleton`, so a long
    worker cannot leak progress state between documents.
12. **`env()` outside config.** Read wrapper overrides through `config('services.crossref.*')` /
    `config('scoring.*')` only (config caching).

### 13.2 Deliberate deviations from the broad plan (accepted, documented)

1. **D-04-05** — findings/candidates are written by `ScoreReferencesStep`, not
   `ValidateReferencesStep`; validation carries transient data on the context. Same DB end state,
   one writer, one transaction.
2. **D-04-01** — the outage/transient distinction is a "did any response arrive" rule rather than an
   unspecified policy; the threshold alternative is documented (§16 Q-1).
3. **D-04-02** — a bibliographic search is always attempted when searchable data exists, so a
   conflicting DOI can suggest the correct publication.
4. **D-04-09** — candidate `match_reason` uses Indonesian component strings rather than the
   illustrative English string in `docs/API_SPEC.md` §5; centralized in `MatchReasonBuilder`.
5. **D-04-11** — scoring value objects live under `App\Services\Scoring\`, not `app/Data/`.
6. **`EmbeddingIndex`** lives under `App\Services\Analysis\` (pipeline artifact) rather than in the
   scoring package.

### 13.3 Rollback

Each task is a self-contained commit with no migration and no backfill. Reverting T4 restores the
Phase 03 provider (Phase 04 steps absent) and the pipeline completes with the Phase 03 subset; the
Crossref/scoring classes become unreferenced but harmless. Reverting T1/T2 alone removes the
unreferenced libraries. No data migration is ever required.

### 13.4 Scaling path (documented, not implemented)

v1 is sequential and polite: one DOI lookup (cached) + one bounded search per reference, with
connect/read timeouts and bounded retries. For very large bibliographies the future path is:
raise `rows` only if needed, batch candidates across references into fewer `/v1/embeddings` calls
(already batched), add a Crossref-specific rate limiter, and optionally fan out lookups with a
bounded concurrency primitive. None of this changes the step contract; the scorer is already a pure
function. Do not introduce concurrency in v1.

---

## 14. Hand-off contract for Phase 05+

Phase 05 may rely on, and must not duplicate:

| Primitive | Location | Usage rule |
|---|---|---|
| Crossref client | `App\Services\Crossref\CrossrefClient` | network only; never call it from scoring or the API layer |
| DOI utility | `App\Services\Crossref\DoiNormalizer` | reuse; never fork |
| Scoring engine | `App\Services\Scoring\*` | pure; Phase 07 tunes `config/scoring.php`, not the classes |
| Verdict decision | `App\Services\Scoring\VerdictDecider` | the only verdict writer; manual review (Phase 05) is a separate, explicit path |
| Finding persistence | `App\Services\ReferenceFinding\ReferenceFindingWriter` | automated findings only; manual review writes its own audit fields |
| Steps | `ValidateReferencesStep` / `EmbedReferencesStep` / `ScoreReferencesStep` | do not re-fetch or re-score in Phase 05; read persisted findings/candidates |
| Context | `AnalysisContext` | Phase 05 adds its own typed accessors; never a generic bag |
| Progress | `AnalysisProgress` (scoped) | `report()` inside long loops |
| Failure mapping | `AnalysisFailureHandler` | Crossref literal already present |

Phase 05 must resolve citations from persisted references/candidates/findings (not from the cleared
context) and derive citation status through the single `CitationStatusResolver`. It removes the
`ResolvingCitations` stub in the pipeline tests when it lands.

---

## 15. Open questions / ambiguities (with recommended defaults)

The plan proceeds with the recommended answer for each. Confirm or redirect; Q-1 and Q-2 change
implementation shape, the rest change messages/config.

| # | Question | Recommendation (adopted above) |
|---|---|---|
| **Q-1** | How exactly is "total Crossref outage" vs "per-reference transient failure" decided? | A **completed exchange (a 2xx hit/list or a 404 miss) proves reachability**; a connection error or 5xx-after-retries before any completed exchange fails the document, and one after a completed exchange degrades that reference to `pending` (D-04-01). Alternative: a consecutive-failure circuit breaker (`crossref.outage.failure_threshold`, default 3) so a single flaky first call does not fail the document. |
| **Q-2** | Should validation fetch and scoring persist, or should validation persist candidates + a placeholder finding as the broad plan sketches? | **Fetch in validation, persist in scoring** (D-04-05): one decision class, one transaction, no placeholder rows, context carries transient data. |
| **Q-3** | For a conflicting/non-resolving/malformed DOI, should a bibliographic search still run to suggest the correct publication? | **Yes, best-effort** whenever searchable data exists (D-04-02); cost is bounded by `rows`. Alternative: search only when there is no DOI, keeping a conflicting DOI informational. |
| **Q-4** | Finding `reason` and candidate `match_reason` language. | Finding reasons Indonesian (matches all spec examples). Candidate `match_reason` also Indonesian via `MatchReasonBuilder`; the §5 example is English but illustrative (D-04-09). |
| **Q-5** | Semantic-vs-string blend inside the title signal. | `scoring.semantic.title_blend = 0.6` (semantic weight); provisional, tuned in Phase 07 (OQ-12). |
| **Q-6** | Caching DOI misses. | **Yes** (D-04-07), so retries/duplicates do not re-hit Crossref; searches are not cached. |
| **Q-7** | `confidence` for an `invalid` DOI-conflict verdict. | The DOI candidate's agreement score (evidence the DOI is wrong), while `selected_candidate_id` points at the best candidate (suggested correct work) (D-04-04). |
| **Q-8** | Intra-step progress access for steps. | `AnalysisProgress` becomes a `scoped` binding so the pipeline and steps share one per-run instance (D-04-06); the `PipelineStep` interface is unchanged. |
