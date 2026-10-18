# Phase 03 — Analysis Pipeline & Inference Client (Detailed Implementation Plan)

> **Status:** detailed plan — ready to implement
> **Parent:** [`03-analysis-pipeline.md`](03-analysis-pipeline.md)
> **Depends on:** [Phase 02](02-document-lifecycle-detail.md) (implemented) · **Unblocks:** Phase 04
> Canonical references: `docs/API_SPEC.md` §2.6/§9/§10/§11, `docs/ARCHITECTURE.md` §7/§7.1/§8/§10,
> `docs/SECURITY.md` §3, `docs/TEST_PLAN.md` T-PIPE / T-INF, `AGENTS.md` §3/§8/§11/§12/§14.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `03-analysis-pipeline.md` into executable tasks. It keeps the phase boundary
in `03-analysis-pipeline.md` §2: it defines the **step machine**, the **internal inference client**
and the **extraction persistence contract**; it does **not** implement Crossref lookup/scoring
(Phase 04), citation resolution (Phase 05), report generation (Phase 06) or the FastAPI service
(OQ-13). It makes **no** `docs/API_SPEC.md` or `docs/DB_SCHEMA.md` change — the schema already
carries every column used and `reference_index` is intentionally not persisted (see §4 D-03-02).

Every task is independently reviewable and leaves the repository green (`php artisan test --compact`
+ `vendor/bin/pint --dirty`). The cross-phase seams are explicit: the pipeline is registered now, and
Phases 04/05 append their steps to the registry without touching the runner, the progress reporter or
the failure handler (§15).

> **Read first.** The one genuinely cross-phase question — what a document does while Phases 04/05
> steps do not exist yet — is resolved in §4 (D-03-01) and re-stated for confirmation in §16 (Q-1).
> The plan proceeds with the recommended answer; if the answer changes, only §4 and §11.1 change.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.x`; PHP CLI `8.5`; `composer.json` requires `^8.3` |
| `spatie/laravel-data` | `4.x` (`app/Data/`, `BaseData`/`ModelData`, `#[MapName(SnakeCaseMapper::class)]`) |
| Pest / DB / queue | Pest `5`, in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (`phpunit.xml`) |
| Existing Phase 01/02 primitives | `ApiResponse`/`ApiError`/`ApiException`; `InferenceUnavailableException`, `ResourceNotFoundException`, `StateConflictException`, `FileStorageException`; `OwnedResourceFinder`; all canonical enums; `AnalysisStep::ordered()` |
| Job envelope | `App\Jobs\AnalyzeDocumentJob` exists (`ShouldQueue`, `$tries = 1`, config queue/timeout, status guard, `failed()` safety net) **without** `middleware()` |
| Pipeline seam | `App\Services\Analysis\Contracts\RunsDocumentAnalysis` exists, **not bound** (Phase 02 tests always `Bus::fake()`) |
| State writes | `App\Services\Document\DocumentAnalysisStateService` (`start`/`advance`/`complete`/`resetToQueued`/`fail`) is the single writer |
| Reset | `App\Services\Document\DocumentAnalysisResetService::reset()` deletes all derived rows in FK-safe order |
| Summary | `App\Services\Document\DocumentSummaryService` (`forDocument`/`forDocuments`) uses `CitationStatusResolver`; `forDocument` returns `null` unless `completed` |
| Config | `config/analysis.php` has only `queue`/`timeout`; `config/services.php` already has the `inference` block (`base_url`, `timeout`, `connect_timeout`, `embedding_batch_size`); `config/scoring.php` exists |
| Storage | default `local` disk rooted at `storage/app/private`; `DocumentFileManager` resolves the disk via `config('filesystems.default')` and stores `documents/{ownerId}/{hashName}` |
| Fixtures | `tests/Fixtures/crossref/doi-found.json` only; `tests/Support/{DocumentTree,Fixtures}.php` exist; **no** inference fixtures yet |
| Missing for this phase | pipeline implementation + binding; `WithoutOverlapping`; progress map; inference client/DTOs/exceptions; extraction persistence; failure handler; inference fixtures/tests |

Facts that constrain the implementation:

- `researched_documents.status` is an enum cast; `analysis_step` is `?AnalysisStep`; `analysis_progress`
  is an `int`. The retry path already resets derived rows and re-dispatches the job.
- Unique indexes in play for persistence: `(researched_document_reference_id, location_index)`,
  `(citation_id, location_index)`, `(researched_document_id, occurrence_index)`,
  `reference_findings.researched_document_reference_id`. The first three are written in this phase
  and force deterministic reindexing of locations/occurrences.
- `researched_document_citations.researched_document_reference_id` is `nullOnDelete`; this phase
  leaves it `null` (resolution is Phase 05).
- `files` is polymorphic with no FK; the uploaded PDF for a document is reached through the
  `ResearchedDocument::file()` `morphOne` relation and its stored `path`/`filename`.
- `AnalyzeDocumentJob::handle()` already re-queries the document, aborts quietly if missing, and
  aborts for terminal states; the pipeline can assume a `pending`/`processing` document.
- `QUEUE_CONNECTION=sync` in tests means a dispatched job runs inline; Phase 03 tests therefore
  either call the pipeline directly or bind test doubles rather than letting arbitrary jobs execute.
- The inference service is **not implemented** (OQ-13): all client tests use `Http::fake()` and
  recorded payloads; there is no production stub.

---

## 2. Execution model

### 2.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Inference DTOs (`app/Data/Inference/`) + `ExtractionFailedException` | M | — | `feat(backend): add inference data transfer objects` |
| **T2** | `InferenceClient` (`app/Services/Inference/`) + client tests | L | T1 | `feat(backend): add internal inference client` |
| **T3** | Step machine primitives: `PipelineStep`, `AnalysisContext`, `AnalysisProgress`, `AnalysisFailureHandler`, `analysis.progress` config | L | — | `feat(backend): add analysis step machine primitives` |
| **T4** | `DoiNormalizer` + `PersistExtractionStep` (normalization + bulk persistence + idempotency) | L | T1, T3 | `feat(backend): persist extracted references and citations` |
| **T5** | `AnalysisPipeline`, `AnalysisStepRegistry`, `ExtractDocumentStep`, `FinalizeAnalysisStep`, provider binding, job middleware | L | T2, T3, T4 | `feat(backend): run the analysis pipeline` |
| **T6** | Inference fixtures + `Tests\Support` fakes + full T-PIPE/T-INF-BE/F-PIPE matrix | L | T5 | `test(backend): cover the analysis pipeline with faked inference` |
| **T7** | Docs sync + full-suite exit validation | S | T1–T6 | `docs: sync analysis pipeline status` |

T1–T3 are independent and can land in parallel; T4 needs T1+T3 (it consumes the DTOs and the
`AnalysisContext`); T5 wires them together and is the first commit that makes a real document reach
`completed`; T6 hardens with the contract fixtures. No migration is added in this phase.

### 2.2 Per-task loop

1. Re-read the task and the canonical `docs/API_SPEC.md` §9/§10 section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Only T7 touches status docs; do not churn docs mid-phase.

### 2.3 Exit criteria (from README §9 and `03-analysis-pipeline.md` §4/§5)

- T-PIPE-01..05, T-INF-BE-01..04 and F-PIPE-01..03 pass (scope-adjusted per §11.1).
- A document reaches `completed`, `analysis_progress = 100`, `analysis_step = completed` through the
  real pipeline with faked inference; every failure path reaches `failed` with a safe `analysis_error`.
- Progress never decreases and only reaches `100` through `DocumentAnalysisStateService::complete()`.
- `PersistExtractionStep` is idempotent: running it twice on the same document causes no
  unique-index violations and no duplicate rows.
- `RunsDocumentAnalysis` is bound to `AnalysisPipeline`; `AnalyzeDocumentJob` carries
  `WithoutOverlapping`.
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- No secret, `.env` value or credential added.
- `docs/ARCHITECTURE.md` §13, `AGENTS.md` §14 and `docs/plans/backend/README.md` §3 describe the new
  reality.

---

## 3. Conventions this phase locks in

Reused from Phases 01/02 (do not re-implement):

- Responses only through `ApiResponse`/`ApiError`; the pipeline never touches the HTTP layer.
- All document state writes go through `DocumentAnalysisStateService`; all derived-row deletion goes
  through `DocumentAnalysisResetService`.
- The business rule for a step lives in exactly one step class; the runner only orchestrates order,
  progress and failures.
- User-facing `analysis_error` strings are safe constants; raw exceptions go to the log only
  (`docs/SECURITY.md` §3).
- Thresholds/timeouts/limits are configuration, not literals in step code.
- Naming: `final class` + constructor property promotion + explicit types, PHPDoc over inline
  comments; `snake_case` string values at the boundary.

Phase-03-specific conventions:

- **Steps are idempotent and transactional per step.** Re-running any step from a clean derived state
  is safe; steps never rely on another step having run earlier in the same process for durable data.
- **Durable inter-step data lives in the database; transient data lives in `AnalysisContext`.** The
  extraction payload is the only transient artifact in this phase and is *consumed and cleared* by
  `PersistExtractionStep`.
- **The runner is step-order agnostic.** It sorts registered steps by `AnalysisStep::ordered()` and
  rejects duplicates, so Phases 04/05 append steps without editing the runner.
- **The inference client owns transport only.** It maps wire → DTO, raises typed exceptions, and
  never makes a verdict. Verdicts exist only after Phase 04.
- **No schema change.** `reference_index` is not persisted (D-03-02); Phase 05 re-derives the
  bibliography order.

---

## 4. Decision log additions

These extend the README `Decision log`. They are internal implementation decisions, not API/schema
changes; each is recorded so later phases do not re-litigate it.

| ID | Question | Decision |
|---|---|---|
| **D-03-01** | Steps for Crossref/embedding/scoring/resolution arrive in Phases 04/05. What does the runner do with a canonical step that has no implementation yet? | The runner executes the **registered subset** in canonical order, so Phase 03 lands with `extracting → persisting → generating_report → completed`. Missing steps are simply absent from `AnalysisStepRegistry`; they are **not** stubbed in production. Tests that need the full sequence register test-double steps (§11.1). This keeps the runner free of placeholders; the interim build is not deployed until Phases 04/05 land (README §7 phase map). |
| **D-03-02** | `reference_index` from `/v1/extract` has no column. Persist it? | No schema change. The payload field is used only transiently (never written); Phase 05 resolves IEEE ordinals from bibliography order (`text_start_offset` ASC, nulls last, `id` tiebreak) per OQ-18. Confirms the broad-plan recommendation. |
| **D-03-03** | How do failures transition a document? | The pipeline catches `Throwable`, the `AnalysisFailureHandler` logs the raw exception (document id + step + correlation id) and writes a safe `analysis_error` through `DocumentAnalysisStateService::fail()`. The pipeline **does not rethrow** (Q-3): the document state is the record; `AnalyzeDocumentJob::failed()` remains the worker-level safety net for timeout/kill/unresolvable-dependency crashes. |
| **D-03-04** | Inference DTO location. | `app/Data/Inference/` (following the established `app/Data/<Entity>/` convention) rather than the README §5 sketch's "client + DTOs" folder. The client lives in `app/Services/Inference/`; the DTOs stay with the other `spatie/laravel-data` classes. |
| **D-03-05** | `InferenceClient::extract` input. | Accept the `File` model so the client can read the stored object and send the original filename (`extract(File $file)`), instead of a bare storage path (Q-4). The client resolves the disk through `config('filesystems.default')`, the same source `DocumentFileManager` uses. |
| **D-03-06** | Where is DOI normalization implemented, given Phase 04 owns the Crossref client? | Implement `App\Services\Crossref\DoiNormalizer` in Phase 03 as a pure, dependency-free utility; Phase 04 reuses it. `normalize()` is best-effort (strips prefixes/URL encoding, lowercases, trims punctuation) and preserves "a DOI string was present"; `isValid()` checks the `10.\d{4,9}/\S+` shape. Malformed-but-present DOIs are stored so Phase 04 can classify them `invalid` (Q-7). |
| **D-03-07** | Progress writes could storm on large documents. | `AnalysisProgress` writes integer progress only when the value changes (≤ 20 writes per step, since each step spans ≤ 20 points), and `DocumentAnalysisStateService::advance()` already enforces monotonicity. No per-item timer/batching config is added. |
| **D-03-08** | Malformed extraction payloads: fatal or sanitize? | **Structural** violations are fatal (`ExtractionFailedException::malformedPayload()`): non-array payload, missing/empty `citation_text`, non-numeric bbox, `page_number < 1`, oversized text. **Semantically implausible** values are sanitized deterministically: out-of-range `publication_year` → `null`, negative offsets kept as-is, `start > end` swapped. Rationale in §8.4. |
| **D-03-09** | `/v1/embeddings` 4xx (e.g. 422 "empty texts"). | Fatal generic failure, not a degradation (Q-5). 5xx/timeout/connection → `InferenceUnavailableException`, which Phase 04's `EmbedReferencesStep` catches and degrades (OQ-04). A 4xx is a backend/contract bug and must be visible. Phase 03's client still guards against empty input locally. |
| **D-03-10** | `WithoutOverlapping` key/expiry. | Key `document-analysis:{documentId}`, `expireAfter($timeout + analysis.lock_expiry_buffer)` (default buffer 60 s), `dontRelease()` (Q-8). A dropped duplicate is safe: the status guard + idempotent steps make a second concurrent run redundant. |
| **D-03-11** | `generating_report` step behaviour. | `FinalizeAnalysisStep` does **not** render a report (OQ-08). It logs a structured completion record (document id, reference/citation counts via `DocumentSummaryService::countsFor()`), then the runner calls `DocumentAnalysisStateService::complete()`. |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all existing tests pass (146 expected after Phase 02).
- [ ] `git status` clean / expected branch.
- [ ] Re-read `03-analysis-pipeline.md` §2–§7 and the README `Decision log`; no open question blocks
      T1–T6 (the §16 questions have documented defaults).
- [ ] Confirm no `.env`/secret is read into code or docs.
- [ ] Confirm no `references`/`citations`/`findings` endpoints exist yet (Phase 05 owns them).

No commit for T0.

---

## 6. T1 — Inference DTOs and extraction exception

**New files:** `app/Data/Inference/{ExtractionResultData,ExtractedReferenceData,ExtractedCitationData,ExtractedLocationData,HealthData,EmbeddingResultData}.php`,
`app/Exceptions/ExtractionFailedException.php`
**Tests:** `tests/Unit/InferenceDataTest.php`

### 6.1 Why these DTOs

`docs/API_SPEC.md` §9 is the internal contract between Laravel and FastAPI. Modelling it as
`spatie/laravel-data` DTOs (a) gives one typed shape shared by the client, the persistence step and
the Phase 04/05 steps, (b) lets malformed payloads fail at the boundary instead of deep in the
pipeline, and (c) keeps the wire contract testable from recorded fixtures.

All DTOs use `#[MapName(SnakeCaseMapper::class)]` so `snake_case` wire keys map to `camelCase`
properties. Nested location lists use `#[DataCollectionOf(ExtractedLocationData::class)]`.

### 6.2 `ExtractedLocationData`

```php
#[MapName(SnakeCaseMapper::class)]
final class ExtractedLocationData extends BaseData
{
    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?float $pageWidth = null,
        public ?float $pageHeight = null,
    ) {}
}
```

`coordinate_system` and `location_index` are **not** part of the wire payload; persistence assigns
`pdf_points_top_left` and a reindexed `0..N-1` (`docs/DB_SCHEMA.md`).

### 6.3 `ExtractedReferenceData` / `ExtractedCitationData`

```php
#[MapName(SnakeCaseMapper::class)]
final class ExtractedReferenceData extends BaseData
{
    /** @param list<ExtractedLocationData> $locations */
    public function __construct(
        public ?string $rawText = null,
        public ?string $doi = null,
        public ?string $title = null,
        public ?string $authors = null,
        public ?string $publicationName = null,
        public ?int $publicationYear = null,
        public ?int $textStartOffset = null,
        public ?int $textEndOffset = null,
        #[DataCollectionOf(ExtractedLocationData::class)]
        public array $locations = [],
    ) {}
}

#[MapName(SnakeCaseMapper::class)]
final class ExtractedCitationData extends BaseData
{
    /** @param list<ExtractedLocationData> $locations */
    public function __construct(
        public string $citationText,
        public ?string $citationMarker = null,
        public ?string $contextBefore = null,
        public ?string $contextAfter = null,
        public ?int $textStartOffset = null,
        public ?int $textEndOffset = null,
        public ?int $occurrenceIndex = null,
        public ?int $referenceIndex = null,
        #[DataCollectionOf(ExtractedLocationData::class)]
        public array $locations = [],
    ) {}
}
```

`referenceIndex` is carried for Phase 05's intra-run use but is **not persisted** (D-03-02).

### 6.4 `ExtractionResultData`

```php
#[MapName(SnakeCaseMapper::class)]
final class ExtractionResultData extends BaseData
{
    /** @param list<ExtractedReferenceData> $references @param list<ExtractedCitationData> $citations */
    public function __construct(
        #[DataCollectionOf(ExtractedReferenceData::class)]
        public array $references = [],
        #[DataCollectionOf(ExtractedCitationData::class)]
        public array $citations = [],
    ) {}

    public function isEmpty(): bool;
}
```

### 6.5 `HealthData` / `EmbeddingResultData`

```php
final class HealthData extends BaseData
{
    public function __construct(
        public string $status,
        public ?string $grobid = null,
        public ?string $sbert = null,
    ) {}

    public function isHealthy(): bool; // status === 'ok'
}
```

```php
#[MapName(SnakeCaseMapper::class)]
final class EmbeddingResultData extends BaseData
{
    /** @param list<list<float>> $embeddings */
    public function __construct(
        public string $model,
        public int $dimensions,
        public array $embeddings = [],   // plain list of float lists; validated in the constructor
    ) {}

    /** @return list<list<float>> */
    public function vectors(): array;
}
```

`embeddings` is a list of numeric lists, so it is **not** a `DataCollectionOf`; the constructor
validates that every vector is a list of exactly `$dimensions` floats (D-03-09 / T-INF-BE-02).

### 6.6 `ExtractionFailedException`

```php
namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A fatal extraction failure (unparseable PDF, unreadable stored file, malformed
 * inference payload). Never rendered directly to the API; the analysis failure
 * handler maps it to a safe `analysis_error`.
 */
final class ExtractionFailedException extends RuntimeException
{
    public static function fileMissing(): self;          // document has no stored file
    public static function fileUnreadable(): self;       // disk stream/copy failed
    public static function invalidPdf(?Throwable $previous = null): self;   // /v1/extract 4xx
    public static function malformedPayload(?Throwable $previous = null): self;
    public static function payloadTooLarge(): self;
}
```

Factories keep construction explicit and testable. The **safe message** used for `analysis_error`
is owned by `AnalysisFailureHandler` (not by the exception) so the mapping is auditable in one place
(D-03-03).

### 6.7 Tests (`tests/Unit/InferenceDataTest.php`)

- `ExtractionResultData::from(Fixtures::json('inference/extract')['data'])` maps nested references,
  citations and locations to the expected camelCase properties and counts.
- Optional wire fields default: a reference without `doi`/`publication_year` maps to `null`.
- `EmbeddingResultData` rejects a vector whose length ≠ `dimensions` (exception) and accepts a valid
  payload.
- `HealthData::isHealthy()` is true only for `status === 'ok'`.
- `ExtractionResultData::isEmpty()` is true for `['references' => [], 'citations' => []]`.

---

## 7. T2 — `InferenceClient`

**New file:** `app/Services/Inference/InferenceClient.php`
**Tests:** `tests/Feature/InferenceClientTest.php`

### 7.1 Shape

```php
namespace App\Services\Inference;

final class InferenceClient
{
    public function health(): HealthData;
    public function extract(File $file): ExtractionResultData;

    /**
     * Embed every text in one logical call; the client batches by
     * `services.inference.embedding_batch_size` and merges results in order.
     *
     * @param  list<string>  $texts  non-empty strings, one per item (order preserved)
     */
    public function embeddings(array $texts): EmbeddingResultData;
}
```

The client is **internal**: no public route, no frontend access, never referenced from a controller
(`AGENTS.md` §3). It depends only on `Illuminate\Support\Facades\Http` and `Storage` plus config.

### 7.2 Request builder

```php
private function pending(): PendingRequest
{
    return Http::baseUrl(rtrim((string) config('services.inference.base_url'), '/'))
        ->timeout((int) config('services.inference.timeout'))
        ->connectTimeout((int) config('services.inference.connect_timeout'))
        ->acceptJson();
}
```

### 7.3 `health()`

- `GET /health`; success → `HealthData::from($response->json())`.
- Connection error/timeout/5xx → `InferenceUnavailableException::serviceUnavailable($previous)`.
- Used only by the opt-in OQ-01 preflight (disabled by default); no pipeline call site in this phase.

### 7.4 `extract(File $file)`

- Resolve the disk once via `config('filesystems.default')` (same source as `DocumentFileManager`).
- Open a read stream (`Storage::disk($disk)->readStream($file->path)`); a missing file →
  `ExtractionFailedException::fileMissing()`; a failed stream → `ExtractionFailedException::fileUnreadable()`.
- `attach('file', $stream, $file->filename, ['Content-Type' => 'application/pdf'])` and
  `POST /v1/extract`; `finally` closes the resource.
- Outcomes:
  - 2xx + `is_array($response->json('data'))` → `ExtractionResultData::from($response->json('data'))`;
    a DTO construction failure → `ExtractionFailedException::malformedPayload($e)`.
  - 422 or other 4xx → `ExtractionFailedException::invalidPdf()` (fatal, T-INF-BE-04).
  - 503/other 5xx → `InferenceUnavailableException::serviceUnavailable()` (T-INF-BE-03).
  - connection error/timeout → `InferenceUnavailableException::serviceUnavailable($e)`.
- Never log or echo the raw response body (it may contain document text).

### 7.5 `embeddings(array $texts)`

- Guard: every entry must be a non-empty string → otherwise `InvalidArgumentException` (a caller bug,
  not a service failure); an empty `$texts` list returns an empty `EmbeddingResultData` without HTTP.
- Chunk by `max(1, (int) config('services.inference.embedding_batch_size', 32))`.
- Per chunk `POST /v1/embeddings` with `['texts' => $chunk, 'model' => 'sbert']`.
- Validate the merged result: every chunk's `dimensions` must match; total vectors must equal the
  input count; otherwise `RuntimeException`/`ExtractionFailedException::malformedPayload()` (contract
  violation, fatal).
- Error mapping: 5xx/timeout/connection → `InferenceUnavailableException`; 4xx → a fatal
  `RuntimeException` (`InferenceClientException::unexpectedResponse()`), per D-03-09.

### 7.6 Tests (`tests/Feature/InferenceClientTest.php`)

- **T-INF-BE-01 (client half):** `Http::fake` with `Fixtures::json('inference/extract')` → DTO
  properties match; assert the request method/URL and that a multipart `file` part was attached
  (`Http::assertSent`).
- **T-INF-BE-02:** 70 texts, batch size 32 → exactly 3 requests (32/32/6); merged
  `EmbeddingResultData` preserves order and count; mixed dimensions → fatal.
- **T-INF-BE-03:** `Http::fake` returning 503, and a connection-error closure → both raise
  `InferenceUnavailableException`.
- **T-INF-BE-04:** `Http::fake` returning 422 → `ExtractionFailedException`.
- **Health:** `GET /health` maps to `HealthData`.
- **No secrets logged:** `Log::spy()` asserts no response body is logged on failure.

---

## 8. T3 — Analysis step machine primitives

**New files:** `app/Services/Analysis/Contracts/PipelineStep.php`,
`app/Services/Analysis/AnalysisContext.php`, `app/Services/Analysis/AnalysisProgress.php`,
`app/Services/Analysis/AnalysisFailureHandler.php`
**Modified:** `config/analysis.php`, `.env.example`
**Tests:** `tests/Feature/AnalysisProgressTest.php`, `tests/Feature/AnalysisFailureHandlerTest.php`

### 8.1 `PipelineStep` contract

```php
namespace App\Services\Analysis\Contracts;

interface PipelineStep
{
    /** The canonical step this handler implements. */
    public function step(): AnalysisStep;

    /**
     * Execute the step. Durable state goes to the database; the handler may read
     * or write transient artifacts on the shared context.
     */
    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

The interface is narrow on purpose: a step is a unit of work plus its canonical label. Ordering,
progress and failure handling are the runner's job.

### 8.2 `AnalysisContext`

```php
final class AnalysisContext
{
    private ?ExtractionResultData $extraction = null;

    public function setExtraction(ExtractionResultData $extraction): void;
    public function hasExtraction(): bool;

    /** @throws LogicException when no extraction has been set */
    public function extraction(): ExtractionResultData;

    /** Consume and clear the extraction: persistence holds it once, then frees the payload. */
    public function takeExtraction(): ExtractionResultData;
}
```

No generic array bag: each artifact is a typed accessor, so a missing dependency fails loudly instead
of producing `null`-driven behaviour. Phase 04 adds typed `embeddings` accessors the same way.

### 8.3 `AnalysisProgress`

```php
final class AnalysisProgress
{
    /** Per-step "last written" values, reset by begin() — see D-03-07. */
    private array $lastWritten = [];

    public function begin(ResearchedDocument $document): void;                    // queued floor
    public function enter(ResearchedDocument $document, AnalysisStep $step): void;    // floor
    public function report(ResearchedDocument $document, AnalysisStep $step, int $done, int $total): void;
    public function leave(ResearchedDocument $document, AnalysisStep $step): void;    // ceiling
    public function complete(ResearchedDocument $document): void;                 // 100 + completed
}
```

Rules:

- The floor/ceiling map comes from `config('analysis.progress.{step}')`; a missing entry throws
  `InvalidArgumentException` (fail fast at the first run, not silently at 0).
- `report()` computes `floor + (int) round((ceiling - floor) * done / total)` with `total > 0`;
  it writes only when the result advances `$lastWritten[$step]`, bounding writes (D-03-07).
- `leave()` always writes the ceiling (so a step with zero reported items still reaches it).
- `complete()` delegates to `DocumentAnalysisStateService::complete()` — the only path to 100.
- All writes go through `DocumentAnalysisStateService`; the reporter never runs raw SQL.

### 8.4 `AnalysisFailureHandler`

```php
final class AnalysisFailureHandler
{
    public function __construct(private readonly DocumentAnalysisStateService $state) {}

    public function handle(ResearchedDocument $document, Throwable $exception, AnalysisStep $step): void;
}
```

Behaviour:

1. Generate a `Str` UUID correlation id.
2. `Log::error('Document analysis failed.', ['document_id' => …, 'step' => $step->value, 'correlation_id' => …, 'exception' => $exception])`
   — the raw exception (with trace) reaches the log only.
3. Resolve a safe message and call `state->fail($document->getKey(), $safeMessage)` (a no-op for a
   missing/terminal document, so a late failure never overwrites a finished run).

Safe-message mapping (literals owned here; T-INF-BE-03 asserts the inference literal equals
`InferenceUnavailableException::serviceUnavailable()->getMessage()` so the two cannot drift):

| Cause | `analysis_error` |
|---|---|
| `ExtractionFailedException` | `Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.` |
| `InferenceUnavailableException` | `Layanan analisis tidak tersedia. Coba lagi nanti.` (its existing message) |
| everything else | `Analisis dokumen gagal. Silakan coba lagi.` |
| Phase 04 `CrossrefUnavailableException` | `Validasi Crossref tidak tersedia. Coba lagi nanti.` (added in Phase 04) |

### 8.5 `config/analysis.php` additions

```php
// Canonical progress floors/ceilings per step (`03-analysis-pipeline.md` §3.6).
'progress' => [
    'queued'              => ['floor' => 0,   'ceiling' => 0],
    'extracting'          => ['floor' => 5,   'ceiling' => 25],
    'persisting'          => ['floor' => 25,  'ceiling' => 35],
    'crossref_validation' => ['floor' => 35,  'ceiling' => 60],
    'embedding'           => ['floor' => 60,  'ceiling' => 70],
    'scoring'             => ['floor' => 70,  'ceiling' => 85],
    'resolving_citations' => ['floor' => 85,  'ceiling' => 95],
    'generating_report'   => ['floor' => 95,  'ceiling' => 99],
    'completed'           => ['floor' => 100, 'ceiling' => 100],
],

// Extra seconds the overlap lock outlives the job timeout (D-03-10).
'lock_expiry_buffer' => (int) env('ANALYSIS_LOCK_EXPIRY_BUFFER', 60),

// Extraction persistence bounds (D-03-08). Text is capped for MySQL `text` portability.
'extraction' => [
    'max_text_length' => (int) env('ANALYSIS_MAX_TEXT_LENGTH', 65535),
    'max_year' => 2100,
],
```

`.env.example` — placeholders only (comments preserved):

```dotenv
ANALYSIS_LOCK_EXPIRY_BUFFER=60
# ANALYSIS_MAX_TEXT_LENGTH=65535
```

### 8.6 Tests

`tests/Feature/AnalysisProgressTest.php`:

- `enter`/`leave` set the exact floor/ceiling; `report` interpolates and is monotonic.
- `report(0, 0)` does not divide by zero and stays at the floor.
- A missing `config('analysis.progress.<step>')` throws.
- `complete()` sets `completed`/100/`completed` step and stamps `analysis_completed_at`.
- Progress never exceeds the step ceiling before `complete()`.

`tests/Feature/AnalysisFailureHandlerTest.php`:

- Each exception type maps to its safe literal and marks the document `failed`.
- A `completed` document is untouched (no overwrite).
- A missing document id does not throw (simulated concurrent delete).
- `Log::spy()` asserts an error log with `document_id`, `step`, `correlation_id`.

---

## 9. T4 — DOI normalization and extraction persistence

**New files:** `app/Services/Crossref/DoiNormalizer.php`,
`app/Services/Analysis/Steps/PersistExtractionStep.php`
**Tests:** `tests/Unit/DoiNormalizerTest.php`, `tests/Feature/ExtractionPersistenceTest.php`

### 9.1 `DoiNormalizer`

```php
namespace App\Services\Crossref;

final class DoiNormalizer
{
    /** Best-effort canonical form; null only when no DOI string is present (D-03-06). */
    public function normalize(?string $raw): ?string;

    /** Strict `10.\d{4,9}/\S+` shape check for a full DOI (Phase 04 uses it). */
    public function isValid(string $doi): bool;
}
```

`normalize()`: trim → strip `doi:` → strip `https?://(dx\.)?doi\.org/` → `rawurldecode` →
`mb_strtolower` → trim trailing `.,;:)]` and surrounding whitespace → `null` if empty. It does
**not** validate; a malformed-but-present string is stored so Phase 04 can classify it `invalid`
(the "DOI present but broken" product case).

### 9.2 `PersistExtractionStep`

```php
final class PersistExtractionStep implements PipelineStep
{
    public function __construct(
        private readonly DocumentAnalysisResetService $resetService,
        private readonly DoiNormalizer $doiNormalizer,
    ) {}

    public function step(): AnalysisStep; // AnalysisStep::Persisting

    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

Algorithm (single `DB::transaction`, idempotent by construction):

```text
1. $extraction = $context->takeExtraction();          // consume + free the payload
2. DB::transaction:
   a. resetService->reset($document);                 // idempotency contract (Phase 02)
   b. validate + normalize the payload (see table)
   c. bulk-insert references (app UUIDs + timestamps, DB::table)
   d. bulk-insert reference locations (location_index 0..N-1)
   e. bulk-insert citations (occurrence_index 0..N-1, reference_id null)
   f. bulk-insert citation locations 0..N-1
```

Mapping (canonical `docs/DB_SCHEMA.md`):

| Payload | Table | Transformed fields |
|---|---|---|
| `references[]` | `researched_document_references` | `doi` normalized; text fields length-capped; offsets int/null |
| `references[].locations[]` | `researched_document_reference_locations` | `page_number` ≥ 1, bbox rounded to 4 dp, `coordinate_system = pdf_points_top_left`, `location_index` reindexed |
| `citations[]` | `researched_document_citations` | `occurrence_index` reindexed; `researched_document_reference_id = null` |
| `citations[].locations[]` | `researched_document_citation_locations` | same as reference locations (`citation_id` parent) |

Normalization rules (D-03-08):

- **Locations:** `location_index` is assigned in payload order `0..N-1` (the service does not send it,
  so duplicates cannot occur on the wire, but the code is defensive). `page_number < 1`, a
  non-numeric bbox, or a non-array location → fatal `malformedPayload`.
- **Occurrences:** collect all `occurrence_index` values; if any is null or duplicated, reindex the
  whole set by `text_start_offset` ASC (nulls last) then payload order, `0..N-1`. This is the only
  way to satisfy `(researched_document_id, occurrence_index)` when extraction returns duplicates.
- **Citations:** `citation_text` is required and non-empty (else fatal). `citation_marker`,
  `context_*` are optional.
- **Texts:** every longer-than-`max_text_length` string → fatal (`payloadTooLarge`), never truncated
  silently.
- **Year/offsets:** `publication_year` outside `[1000, extraction.max_year]` → `null`; offsets with
  `start > end` are swapped.

Bulk inserts use `DB::table(...)->insert($rows)` with `id => (string) Str::uuid()`,
`created_at => now()`, `updated_at => now()`. Rows are chunked (`array_chunk($rows, 500)`) so a very
large bibliography cannot exceed a driver's parameter limit.

Why not Eloquent `createMany`: bulk `insert` avoids per-row events/casts and keeps the step in one
transaction with predictable performance; the models are never hydrated for writes. Reads in later
steps use Eloquent as usual.

### 9.3 Tests (`tests/Unit/DoiNormalizerTest.php`, `tests/Feature/ExtractionPersistenceTest.php`)

`DoiNormalizerTest`:

- `https://doi.org/10.1038/NATURE14539`, `doi:10.1038/nature14539`, `10.1038/nature14539.` all
  normalize to `10.1038/nature14539`.
- `null`/`''`/`'   '` → `null`.
- `isValid()` accepts `10.1038/nature14539`, rejects `not-a-doi`, `10.123/abc`, `https://…`.

`ExtractionPersistenceTest`:

- Happy payload (2 references, 2 citations, multi-page locations) → the exact rows/columns, correct
  `location_index` and `coordinate_system`, DOIs normalized, citation `reference_id` null.
- Duplicate/null `occurrence_index` → reindexed `0..N-1`, ordered by `text_start_offset`.
- Malformed payloads (page 0, missing `citation_text`, over-long text) → `ExtractionFailedException`,
  and the transaction leaves **no** rows behind.
- **F-PIPE-01 (step half):** running `PersistExtractionStep` twice yields no duplicate rows and no
  unique-index violation.
- `takeExtraction()` clears the context (a second call throws `LogicException`).

---

## 10. T5 — Pipeline, steps, provider binding and job middleware

**New files:** `app/Services/Analysis/AnalysisStepRegistry.php`, `app/Services/Analysis/AnalysisPipeline.php`,
`app/Services/Analysis/Steps/ExtractDocumentStep.php`, `app/Services/Analysis/Steps/FinalizeAnalysisStep.php`,
`app/Providers/AnalysisServiceProvider.php`
**Modified:** `app/Jobs/AnalyzeDocumentJob.php`, `bootstrap/providers.php`,
`app/Services/Document/DocumentSummaryService.php`
**Tests:** `tests/Feature/AnalysisPipelineTest.php`, `tests/Feature/AnalyzeDocumentJobTest.php`

### 10.1 `AnalysisStepRegistry`

```php
final class AnalysisStepRegistry
{
    /** @param list<PipelineStep> $steps */
    public function __construct(private readonly array $steps) {}

    /** @return list<PipelineStep> canonical order; throws on duplicate steps */
    public function ordered(): array;
}
```

`ordered()` indexes the canonical order via `AnalysisStep::ordered()` and sorts the registered steps;
a duplicate `step()` value throws `InvalidArgumentException` (so a Phase 04/05 mistake fails loudly).
Registration order therefore never affects execution order.

### 10.2 `ExtractDocumentStep`

```php
final class ExtractDocumentStep implements PipelineStep
{
    public function __construct(private readonly InferenceClient $client) {}

    public function step(): AnalysisStep; // AnalysisStep::Extracting

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $file = $document->file()->first() ?? throw ExtractionFailedException::fileMissing();
        $context->setExtraction($this->client->extract($file));
    }
}
```

### 10.3 `FinalizeAnalysisStep`

```php
final class FinalizeAnalysisStep implements PipelineStep
{
    public function __construct(private readonly DocumentSummaryService $summaryService) {}

    public function step(): AnalysisStep; // AnalysisStep::GeneratingReport

    public function handle(ResearchedDocument $document, AnalysisContext $context): void
    {
        $counts = $this->summaryService->countsFor($document);
        Log::info('Document analysis finalized.', [...counts...]);
    }
}
```

`DocumentSummaryService` gains `countsFor(ResearchedDocument): DocumentAnalysisSummaryData` that
computes the same three aggregate queries **without** the `completed` gate; `forDocument()` keeps its
`null`-unless-completed contract (OQ-10) and delegates to the new private `summariesForIds()`. No
count is persisted — the summary stays derived.

### 10.4 `AnalysisPipeline`

```php
final class AnalysisPipeline implements RunsDocumentAnalysis
{
    public function __construct(
        private readonly DocumentAnalysisStateService $state,
        private readonly AnalysisProgress $progress,
        private readonly AnalysisFailureHandler $failureHandler,
        private readonly AnalysisStepRegistry $registry,
    ) {}

    public function run(ResearchedDocument $document): void
    {
        $context = new AnalysisContext();
        $current = AnalysisStep::Queued;

        try {
            $this->state->start($document);
            $this->progress->begin($document);

            foreach ($this->registry->ordered() as $step) {
                $current = $step->step();
                $this->progress->enter($document, $current);
                $step->handle($document, $context);
                $this->progress->leave($document, $current);
            }

            $this->progress->complete($document);
        } catch (Throwable $exception) {
            // Document deleted mid-run: nothing to transition, no exception noise (F-PIPE-02).
            if (! ResearchedDocument::query()->whereKey($document->getKey())->exists()) {
                return;
            }

            $this->failureHandler->handle($document, $exception, $current);
            // Deliberately not rethrown (D-03-03): the document state is the record.
        }
    }
}
```

### 10.5 `AnalysisServiceProvider`

```php
final class AnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnalysisStepRegistry::class, fn (Application $app): AnalysisStepRegistry
            => new AnalysisStepRegistry([
                $app->make(ExtractDocumentStep::class),
                $app->make(PersistExtractionStep::class),
                // Phase 04 appends ValidateReferencesStep, EmbedReferencesStep, ScoreReferencesStep.
                // Phase 05 appends ResolveCitationsStep.
                $app->make(FinalizeAnalysisStep::class),
            ]));

        $this->app->bind(RunsDocumentAnalysis::class, AnalysisPipeline::class);
    }
}
```

- Bind the registry as a **singleton** (the step list is static per deployment); bind the pipeline as
  **transient** so each run gets a fresh `AnalysisProgress` write cache.
- Register the provider in `bootstrap/providers.php` next to `AppServiceProvider`.
- Do **not** bind `RunsDocumentAnalysis` to a mock/stub in production. Phase 02 tests that let the
  sync queue run must now rebind the registry or the pipeline with test doubles (§11).

### 10.6 `AnalyzeDocumentJob` middleware

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping("document-analysis:{$this->documentId}"))
            ->expireAfter($this->timeout + (int) config('analysis.lock_expiry_buffer', 60))
            ->dontRelease(),
    ];
}
```

The status guard in `handle()` stays; the lock prevents two workers racing a `pending` document. A
duplicate that cannot acquire the lock is dropped (safe, D-03-10). No change to `failed()`.

### 10.7 Tests

`tests/Feature/AnalysisPipelineTest.php`:

- **Ordering:** with stub steps for every canonical step, the recorded invocation order equals
  `AnalysisStep::ordered()` minus `queued`/`completed`; a duplicate registration throws.
- **T-PIPE-01:** real `ExtractDocumentStep` + `PersistExtractionStep` + `FinalizeAnalysisStep` plus
  stub steps for the Phase 04/05 steps, with `Http::fake` inference → rows persisted, `completed`,
  `100`, `completed` step.
- **T-PIPE-04:** a capturing stub records `$document->fresh()->analysis_progress` at each step
  boundary; assert non-decreasing and that `100` appears only on completion.
- **T-PIPE-02 / T-INF-BE-03:** inference 503/connection error → document `failed`, safe
  `Layanan analisis tidak tersedia. Coba lagi nanti.`, no exception escapes `run()`.
- **T-INF-BE-04:** `Http::fake` 422 → `failed` with the unprocessable-document message.
- **F-PIPE-01:** a stub that throws after persistence, then a successful re-run →
  exactly one set of rows, no unique-index violation.
- **F-PIPE-02:** a stub that deletes the document then throws → `run()` returns quietly, throws
  nothing, and does not attempt to update the missing row.
- Test-double steps live in `tests/Support/StubPipelineStep.php` (closure-backed, `step()` +
  `handle()`); the test rebinds `AnalysisStepRegistry` before resolving the pipeline.

`tests/Feature/AnalyzeDocumentJobTest.php` (extend):

- `middleware()` returns one `WithoutOverlapping` whose key contains the document id and whose lock
  expiry is `timeout + buffer`.
- Existing guard/`failed()` assertions stay green (the pipeline mock is still used for `handle()`).

---

## 11. T6 — Inference fixtures, test fakes and the full acceptance matrix

**New files:** `tests/Fixtures/inference/{extract,extract-empty,extract-malformed,embeddings,health,error-extraction}.json`,
`tests/Support/InferenceFake.php`, `tests/Support/StubPipelineStep.php`
**Tests:** `tests/Feature/{AnalysisEndToEndTest,InferenceClientTest,AnalysisPipelineTest}.php`

### 11.1 Fixture-driven fake harness

- `Tests\Support\InferenceFake` wraps `Http::fake([...])` for the configured inference base URL so a
  test can request exactly the stubs it needs:

```php
InferenceFake::extraction();        // 200 + extract.json
InferenceFake::emptyExtraction();   // 200 + extract-empty.json
InferenceFake::malformedExtraction();// 200 + extract-malformed.json
InferenceFake::embeddings($count);  // 200 + generated vectors of the configured dimensions
InferenceFake::unavailable();       // 503 + error envelope
InferenceFake::unparseablePdf();    // 422 + error-extraction.json
InferenceFake::connectionError();   // ConnectionException
```

- Fixtures are recorded/synthetic JSON under `tests/Fixtures/inference/` loaded through the existing
  `Tests\Support\Fixtures::json()` helper.
- `tests/Fixtures/inference/extract.json` intentionally contains: a valid DOI reference, a
  no-DOI reference, a malformed-DOI reference, citations with duplicate/null `occurrence_index`, and
  locations spanning two pages — so normalization is exercised by the same payload the contract test
  uses.
- **T-PIPE-01/03 note:** Phase 03 has no Crossref step; the "full pipeline" test registers
  `StubPipelineStep` handlers for `crossref_validation`/`embedding`/`scoring`/`resolving_citations`
  so the canonical sequence and progress range are validated. Phase 04 replaces the Crossref/embedding/
  scoring stubs with real steps and real `Http::fake` stubs; Phase 05 replaces the resolution stub.

### 11.2 Acceptance matrix (this phase's rows)

| ID | Case | Covered by |
|---|---|---|
| T-PIPE-01 | Full run with faked inference → rows persisted, `completed`, 100, step `completed` | `AnalysisPipelineTest` / `AnalysisEndToEndTest` |
| T-PIPE-02 | Extract failure → `failed` + safe `analysis_error` | `AnalysisPipelineTest` |
| T-PIPE-03 | Crossref per-reference failure (Phase 04) | deferred; the machine's per-step isolation is proven by the stub-step tests |
| T-PIPE-04 | Progress monotonicity | `AnalysisProgressTest` + `AnalysisPipelineTest` |
| T-PIPE-05 | Upload never runs the pipeline synchronously | Phase 02 `UploadDocumentTest` (`Bus::fake`) stays; add an assertion that a faked pipeline is not resolved during upload |
| T-INF-BE-01 | `/v1/extract` contract fixture → DTOs + persisted rows | `InferenceClientTest` + `ExtractionPersistenceTest` |
| T-INF-BE-02 | `/v1/embeddings` batching + dimension validation | `InferenceClientTest` |
| T-INF-BE-03 | Inference 5xx/timeout/connection → `InferenceUnavailableException`; document `failed` | `InferenceClientTest` + `AnalysisPipelineTest` |
| T-INF-BE-04 | Unparseable PDF (422) → fatal extraction failure | `InferenceClientTest` + `AnalysisPipelineTest` |
| F-PIPE-01 | Re-run after partial failure → reset + idempotent persistence | `ExtractionPersistenceTest` + `AnalysisPipelineTest` |
| F-PIPE-02 | Document deleted while queued/mid-run → quiet abort | `AnalyzeDocumentJobTest` + `AnalysisPipelineTest` |
| F-PIPE-03 | Worker timeout → `failed`, never stuck `processing` | `AnalyzeDocumentJobTest` (`failed()` safety net) |

---

## 12. T7 — Documentation sync and final validation

- [ ] `docs/ARCHITECTURE.md` §13 — backend bullet: `AnalysisPipeline` + step machine, inference
      client, extraction persistence, progress/failure handling, `WithoutOverlapping`.
- [ ] `AGENTS.md` §14 — same status update (pipeline internals now exist; keep the "missing" list
      accurate: Crossref validation/scoring, citation resolution, `/references`/`/citations`/
      `/findings`, reports).
- [ ] `docs/plans/backend/README.md` §3.1/§3.2 — move "pipeline collaborator + inference client +
      extraction persistence" from "missing" to "exists"; update the phase-map/status rows.
- [ ] `docs/plans/backend/03-analysis-pipeline.md` — status header points at this detailed plan.
- [ ] Confirm **no** `docs/API_SPEC.md` / `docs/DB_SCHEMA.md` change is required. If a task needed one,
      it is a contract change and must be called out (README §2/§9.6).
- [ ] `php artisan test --compact` (full suite) + `vendor/bin/pint --dirty --format agent`.

---

## 13. Files touched (summary)

```text
backend/app/
├── Data/Inference/                     NEW  ExtractionResultData, ExtractedReferenceData,
│                                            ExtractedCitationData, ExtractedLocationData,
│                                            HealthData, EmbeddingResultData
├── Exceptions/                         +    ExtractionFailedException
├── Jobs/AnalyzeDocumentJob.php         ~    + middleware()
├── Providers/AnalysisServiceProvider   NEW  registry + pipeline binding
├── Services/Analysis/                  NEW  AnalysisPipeline, AnalysisStepRegistry,
│                                            AnalysisProgress, AnalysisFailureHandler,
│                                            AnalysisContext
│   ├── Contracts/                      NEW  PipelineStep
│   └── Steps/                          NEW  ExtractDocumentStep, PersistExtractionStep,
│                                            FinalizeAnalysisStep
├── Services/Crossref/DoiNormalizer.php NEW  (reused by Phase 04)
├── Services/Inference/InferenceClient  NEW
└── Services/Document/DocumentSummaryService.php  ~  + countsFor()/extract summariesForIds()

backend/config/analysis.php             ~    + progress, lock_expiry_buffer, extraction
backend/bootstrap/providers.php         ~    + AnalysisServiceProvider
backend/.env.example                    ~    + ANALYSIS_LOCK_EXPIRY_BUFFER
backend/tests/                          NEW fixtures + Support fakes + pipeline/client/persistence tests
```

No `routes/`, `Http/`, migration or DTO-envelope change.

---

## 14. Risks, pitfalls and deliberate deviations

### 14.1 Pitfalls to avoid

1. **A production stub for a missing step.** Missing canonical steps are absent from the registry
   (D-03-01); never add a no-op `PipelineStep` to satisfy ordering. Test doubles are test-only.
2. **Progress at 100 before completion.** Only `DocumentAnalysisStateService::complete()` sets 100;
   step ceilings max at 99.
3. **Non-idempotent persistence.** `PersistExtractionStep` must reset before inserting, or a re-run
   hits the unique indexes.
4. **Silent truncation of extraction text.** Over-long text is a fatal extraction error (D-03-08),
   not a truncation.
5. **Occurrence collisions.** Reindex `occurrence_index` for the whole set when any value is null or
   duplicated; do not insert duplicates and hope.
6. **Multipart body in memory.** Stream the stored PDF (`readStream`) and close it in `finally`.
7. **Blocking lock without expiry.** `WithoutOverlapping` must set `expireAfter(timeout + buffer)` so
   a killed worker cannot wedge a document forever.
8. **Swallowing failures without logging.** The failure handler always logs the raw exception with
   the document id, step and correlation id; `analysis_error` stays safe.
9. **Concurrent delete.** `AnalysisPipeline` checks document existence before handling a failure;
   `DocumentAnalysisStateService::fail()` re-queries and no-ops on a missing/terminal row.
10. **Over-fetching in finalize.** `countsFor()` uses the same three grouped queries; do not add a
    per-reference loop.

### 14.2 Deliberate deviations from the broad plan (accepted, documented)

1. **D-03-01** — the runner executes the registered subset; not-yet-implemented canonical steps are
   absent rather than stubbed. The broad plan's step list is the eventual registry.
2. **D-03-03** — the pipeline handles failures and does not rethrow; `AnalyzeDocumentJob::failed()`
   stays the worker-level safety net. (Broad plan allows either; this avoids double logging.)
3. **D-03-04** — inference DTOs live in `app/Data/Inference/` rather than under `Services/Inference/`
   per the README §5 sketch.
4. **D-03-05** — `extract(File $file)` instead of `extract(string $storagePath)` so the original
   filename and disk are preserved.
5. **D-03-06** — `DoiNormalizer` ships in Phase 03 (owned by the future `Services/Crossref/` folder)
   to unblock persistence; Phase 04 reuses it verbatim.
6. **`DocumentSummaryService::countsFor()`** is added so `FinalizeAnalysisStep` can log counts without
   weakening the `forDocument()` `completed` gate (OQ-10).

### 14.3 Rollback

Each task is a self-contained commit; there is no migration and no backfill. Reverting T5 removes the
provider binding and the job middleware; a dispatched job would then fail to resolve
`RunsDocumentAnalysis` and its `failed()` safety net marks the document failed (expected fail-loud
behaviour, not silently stuck).

---

## 15. Hand-off contract for Phases 04+

Phase 04/05 may rely on, and must not duplicate:

| Primitive | Location | Usage rule |
|---|---|---|
| Runner | `App\Services\Analysis\AnalysisPipeline` | append steps to the registry; never edit the runner's loop for a new step |
| Step contract | `App\Services\Analysis\Contracts\PipelineStep` | one class per canonical step |
| Registry | `App\Services\Analysis\AnalysisStepRegistry` (`AnalysisServiceProvider`) | append `$app->make(YourStep::class)`; order is canonical, duplicates fail loudly |
| Progress | `App\Services\Analysis\AnalysisProgress` | use `report()` inside long loops; use the new `analysis.progress` map |
| Failure mapping | `App\Services\Analysis\AnalysisFailureHandler` | Phase 04 adds `CrossrefUnavailableException` → `Validasi Crossref tidak tersedia. Coba lagi nanti.` |
| Context | `App\Services\Analysis\AnalysisContext` | Phase 04 adds typed `embeddings` accessors; do not introduce a generic bag |
| Inference client | `App\Services\Inference\InferenceClient` | Phase 04 calls `embeddings()` and catches `InferenceUnavailableException` to degrade (OQ-04) |
| DOI utility | `App\Services\Crossref\DoiNormalizer` | Phase 04 reuses `normalize()`/`isValid()`; do not write a second normalizer |
| Extraction persistence | `App\Services\Analysis\Steps\PersistExtractionStep` | Phase 05 reads persisted references/citations; it must not re-persist or re-extract |
| State/reset | `DocumentAnalysisStateService` / `DocumentAnalysisResetService` | unchanged |
| Summary | `DocumentSummaryService` (`countsFor`/`forDocument`) | reuse; never recompute counts inline |

Explicit Phase 04/05 requirements carried forward:

- Phase 04 binds its steps into `AnalysisServiceProvider` **in canonical order** (the registry sorts,
  but duplicates must not be introduced).
- Phase 04's `EmbedReferencesStep` must degrade on `InferenceUnavailableException` and record the
  degradation in the finding `reason` (OQ-04); extraction failure stays fatal.
- Phase 05's `ResolveCitationsStep` re-derives bibliography order from persisted `text_start_offset`
  (nulls last, `id` tiebreak) and must not rely on the (cleared) extraction context.
- Phase 04/05 remove the corresponding `StubPipelineStep` registrations in the pipeline tests as they
  replace them with real assertions.

---

## 16. Open questions / ambiguities (with recommended defaults)

The plan above proceeds with the recommended answer for each. Confirm or redirect; only Q-1 changes
more than a message string.

| # | Question | Recommendation (adopted above) |
|---|---|---|
| **Q-1** | While Phases 04/05 steps do not exist, should a document still reach `completed` (D-03-01) or stay non-terminal until the full canonical sequence exists? | **Reach `completed`** after the registered subset. This matches "Crossref/scoring/resolution plug into the same step machine" and keeps each phase independently testable. The interim build is not deployed. |
| **Q-2** | Exact safe `analysis_error` for an unparseable PDF. | `Dokumen tidak dapat diproses. Pastikan PDF memuat teks yang dapat diekstrak.` |
| **Q-3** | Should the pipeline rethrow a handled step failure for `failed_jobs` visibility? | **No** — handle + log + swallow; `AnalyzeDocumentJob::failed()` stays the worker-level safety net (D-03-03). |
| **Q-4** | `InferenceClient::extract` input: bare path (broad plan) vs `File`. | `extract(File $file)` to preserve the original filename and reuse the configured disk (D-03-05). |
| **Q-5** | `/v1/embeddings` 4xx: degrade or fatal? | **Fatal** (contract bug); only 5xx/timeout/connection is `InferenceUnavailableException` and thus degradable (D-03-09). |
| **Q-6** | Malformed-but-plausible extraction values (year, offsets) vs structural violations. | Structural → fatal; implausible → sanitize (D-03-08). |
| **Q-7** | Store a best-effort normalized malformed DOI, or `null`? | Store best-effort normalized so "DOI present" survives to Phase 04; `isValid()` flags it (D-03-06). |
| **Q-8** | Lock key/expiry for `WithoutOverlapping`. | Key by document id, `expireAfter(timeout + 60)`, `dontRelease()` (D-03-10). |
