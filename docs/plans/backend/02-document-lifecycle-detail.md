# Phase 02 — Document Lifecycle Endpoints (Detailed Implementation Plan)

> **Status:** detailed plan — **implemented** (Phase 02 complete; `php artisan test` → 146 passed)
> **Parent:** [`02-document-lifecycle.md`](02-document-lifecycle.md)
> **Depends on:** [Phase 01](01-foundation-detail.md) (implemented) · **Unblocks:** Phases 03–07
> Canonical references: `docs/API_SPEC.md` §2.5/§2.6/§2.8/§2.9/§4/§10/§11,
> `docs/DB_SCHEMA.md` (first block), `docs/SECURITY.md` §2/§3/§7/§8,
> `docs/TEST_PLAN.md` T-DOC-01..17 / T-OWN-01..04 / T-OWN-09 / F-SUM-01..02, `AGENTS.md` §6/§7/§8/§9.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `02-document-lifecycle.md` into executable tasks. It keeps the phase
boundary in `02-document-lifecycle.md` §2 (no pipeline step internals, no schema change) but
resolves the one real cross-phase tension — Phase 02 must *dispatch* a job that Phase 03 *fills in*
— with an explicit, non-throwaway seam (§5, decision D-02-01). Every task is independently
reviewable and leaves the repository green (`php artisan test --compact` + `pint`).

If any task reveals a conflict with `docs/API_SPEC.md` / `docs/DB_SCHEMA.md`, stop and resolve it in
the canonical spec first (README §2). Phase 02 makes **no** `API_SPEC.md` or `DB_SCHEMA.md` change:
the endpoint shapes already exist in the spec and the schema already carries every column used.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.x`; PHP CLI `8.5`; `composer.json` requires `^8.3` |
| `spatie/laravel-data` | `4.x` (`app/Data/`, unwrapped `data` envelope) |
| Pest / DB | Pest `5`, in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (`phpunit.xml`) |
| Existing Phase 01 primitives | `ApiResponse` / `ApiError` / `ApiException`; `ResourceNotFoundException`, `StateConflictException`, `InferenceUnavailableException`; `OwnedResourceFinder` + `ScopesThroughDocument`; `DocumentStatus` / `AnalysisStep` / `ReferenceFindingStatus` / `CitationStatus`; `documents` / `document-status` rate limiters; `DocumentTree` + `Fixtures`; enforced morph map |
| Existing routes (`routes/api.php`) | only `/auth/*` and `POST /documents` |
| Existing upload slice | `UploadController::upload` → `DocumentUploadService` (transactional document + file), **does not dispatch a job**, message `Dokumen berhasil diunggah.` |
| Existing models | all nine domain models + factories; `ResearchedDocument` casts `status`/`analysis_step`; `references()`/`citations()`/`findings()`/`reports()`/`file()` relations present |
| Missing for this phase | `/documents` list/detail/status/retry/delete/purge; summary/status DTOs; list query + filter request; retry/reset/deletion services; job dispatch; route-level rate-limit and ownership tests |
| Config | `config/services.php` (crossref/inference), `config/scoring.php` exist; **no `config/analysis.php`** |
| `.env.example` | crossref/inference/scoring placeholders present; no `ANALYSIS_*` |

Facts that constrain the implementation:

- `files` is polymorphic with **no FK** on `fileable_id`; deleting a document does not remove its
  `files` row. `generated_document_reports.file_id` is a plain nullable UUID today (OQ-06 adds the
  FK in Phase 06). Phase 02 deletion must collect file rows explicitly for the document **and** its
  reports before relying on DB cascade.
- `researched_document_citations.researched_document_reference_id` is `nullOnDelete`, so deleting
  references does **not** delete citations — it unpairs them. Reset/purge ordering matters.
- Unique indexes in play: `(researched_document_reference_id, location_index)`,
  `(citation_id, location_index)`, `(researched_document_id, occurrence_index)`,
  `reference_findings.researched_document_reference_id`, `(reference_finding_id, rank)`.
- `DocumentUploadService` stores the file inside the DB transaction and cleans up on failure; any
  file-lifecycle refactor must preserve that atomicity.
- `OwnedResourceFinder` is the only allowed resolution path for document-owned resources; it uses
  `find()` + explicit throw to keep the per-resource 404 message.
- `ApiResponse::collection(LengthAwarePaginator, class-string<Data>)` maps each item through
  `$dataClass::from($item)` and passes through items that are already `Data`.
- No `config/analysis.php` exists, so the dispatched job cannot read a queue name/timeout yet.

---

## 2. Execution model

### 2.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | `DocumentAnalysisStateService` + `DocumentAnalysisResetService` | M | — | `feat(backend): add document analysis state and reset services` |
| **T2** | `config/analysis.php`, `.env.example`, `RunsDocumentAnalysis` seam, `AnalyzeDocumentJob` | M | T1 | `feat(backend): add analysis job dispatch seam` |
| **T3** | Upload dispatch + canonical message + `UploadDocumentTest` updates | S | T1, T2 | `refactor(backend): dispatch analysis job on upload` |
| **T4** | Summary/status DTOs, shared `CitationStatusResolver`, `DocumentSummaryService` | L | T1 | `feat(backend): add document summary DTOs and derived counts` |
| **T5** | `ListDocumentsRequest` + `DocumentQueryService` | M | T4 | `feat(backend): add document list query service` |
| **T6** | `DocumentController` (index/show/status) + routes | M | T5 | `feat(backend): add document list, detail and status endpoints` |
| **T7** | `DocumentController::retry` + `DocumentLifecycleService` + route | M | T1, T6 | `feat(backend): add document retry endpoint` |
| **T8** | `DocumentFileManager` refactor + `DocumentDeletionService` + destroy/purge | L | T6 | `feat(backend): add document deletion and purge` |
| **T9** | Route-level rate-limit smoke + HTTP ownership isolation + T-DOC coverage | M | T6, T7, T8 | `test(backend): cover document lifecycle limits and ownership` |
| **T10** | Docs sync + full-suite exit validation | S | T1–T9 | `docs: sync document lifecycle status` |

T1/T2/T4 are foundational; T5–T8 build the surface in the listed order so each commit stays green.
T3 depends on T1/T2 (job class must exist to dispatch). T9 tightens coverage once all routes exist.

### 2.2 Per-task loop

1. Re-read the relevant task and the canonical spec section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Only T10 touches the status docs; do not churn docs mid-phase.

### 2.3 Exit criteria (from README §9 and `02-document-lifecycle.md` §5)

- All T-DOC-01..17, T-OWN-01..04, T-OWN-09, F-SUM-01..02 rows pass.
- Every `/documents` endpoint matches `docs/API_SPEC.md` §4 (status, envelope, field names,
  messages) exactly.
- Foreign document access returns `404 NOT_FOUND` with `Dokumen tidak ditemukan.` for show/status/
  retry/delete; the body never distinguishes missing from foreign.
- `UploadDocumentTest` asserts the canonical message and `AnalyzeDocumentJob` dispatch.
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- No secret, `.env` value or credential added.
- `docs/ARCHITECTURE.md` §13, `AGENTS.md` §14 and `docs/plans/backend/README.md` §3 describe the
  new reality.

---

## 3. Conventions this phase locks in

Reused from Phase 01 (`01-foundation-detail.md` §3) — do not re-implement:

- Responses only through `ApiResponse` (`single`/`accepted`/`collection`/`noContent`); errors only
  through `ApiError` / domain exceptions. No ad-hoc envelope arrays.
- Document-owned resources resolved only through `OwnedResourceFinder`; foreign → per-resource
  `ResourceNotFoundException`.
- Enums for casts, validation (`Rule::enum`) and DTO types. `CitationStatus::derive()` is the single
  PHP derivation rule; this phase adds a **shared SQL expression** next to it (§7.3) so the summary
  and the Phase 05 resolver cannot drift.
- `per_page` default 15 / max 100; invalid filters → `422`.
- Deterministic pagination: always append `id` as a tiebreaker to `created_at` sorts.
- Rate limiters apply as route middleware; the stricter of `api` and the named limiter wins.

Phase-02-specific conventions:

- **State writes are centralized.** Every write to `researched_documents.status` /
  `analysis_progress` / `analysis_step` / `analysis_error` / `*_at` goes through
  `DocumentAnalysisStateService` (§5.2). Phase 03's pipeline calls the same service.
- **Derived data is never stored.** Summary counts are computed with grouped aggregate queries;
  citation status stays derived.
- **Deletion cleans up files explicitly** because the polymorphic relation has no DB cascade.
- **`summary` is `null` until `completed`** (OQ-10).

---

## 4. Decision log additions

These extend the README `Decision log`. They are internal implementation decisions, not API/schema
changes; each is recorded so later phases do not re-litigate it.

| ID | Question | Decision |
|---|---|---|
| **D-02-01** | Phase 02 must dispatch `AnalyzeDocumentJob`, but Phase 03 owns its pipeline internals. How is the seam drawn? | Phase 02 ships the **final job envelope** (`AnalyzeDocumentJob`: `ShouldQueue`, `tries=1`, configured timeout/queue, `WithoutOverlapping`, status guard, `failed()` safety net) plus the interface `App\Services\Analysis\Contracts\RunsDocumentAnalysis` (single method `run(ResearchedDocument): void`). Phase 03 binds the concrete `AnalysisPipeline` implementation. Phase 02 tests always `Bus::fake()`, so the unbound interface is never resolved. No stub/no-op class is created. |
| **D-02-02** | Several services need to write document state (retry, job failure, Phase 03 progress). Where? | One `App\Services\Document\DocumentAnalysisStateService` owns all `researched_documents` state writes with invariant guards. Retry and the job `failed()` call it now; Phase 03's progress reporter extends it. |
| **D-02-03** | The summary needs derived citation counts before Phase 05's resolver exists. How to avoid two copies of the derivation rule? | `App\Services\Citations\CitationStatusResolver` exposes both `resolve()` (delegating to `CitationStatus::derive()`) and `sqlExpression()` (a portable `CASE` yielding the enum value). A consistency test asserts the SQL buckets equal the PHP buckets over one fixture. Phase 05 reuses this class for per-row status and filters. |
| **D-02-04** | How are list summaries batched without N+1? | Three grouped aggregate queries for the whole page (references count, findings by status, citations by derived status), keyed by document id. `total_references` is the reference count; the four reference buckets come from findings, so a completed document with an un-evaluated reference can have `total_references > valid+suspicious+invalid+not_found`. Documented in the DTO. |
| **D-02-05** | Exact retry response shape (`id/status/progress/current_step` only in the spec example). | Reuse `ResearchedDocumentStatusData` (adds `error: null`, `updated_at`). Extra keys are additive and tolerated by the spec's success-envelope model; avoids a fourth near-identical DTO. |
| **D-02-06** | File store/delete logic must be shared with Phase 06 reports. | `App\Services\Document\DocumentFileManager` owns store/delete of `files` rows + stored objects for any morph owner. `DocumentUploadService` is refactored to use it, preserving the existing transactional behavior. |
| **D-02-07** | `config/analysis.php` does not exist; the job needs queue/timeout. | Create it in Phase 02 with `queue` and `timeout`. Phase 03 adds `progress` and any inference preflight flag. |
| **D-02-08** | Purge must not load the whole history. | `DocumentDeletionService::purge()` iterates `lazyById()` (ordered by `id`) and deletes per document, reusing `delete()`. Deleting already-passed rows is safe for `where id > last`. |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all existing tests pass (89+).
- [ ] `git status` clean / expected branch.
- [ ] Re-read `02-document-lifecycle.md` §2–§6 and the README `Decision log`; no open question
      blocks T1–T10.
- [ ] Confirm no `.env`/secret is read into code or docs.

No commit for T0.

---

## 6. T1 — Document analysis state and reset services

**New files:** `app/Services/Document/DocumentAnalysisStateService.php`,
`app/Services/Document/DocumentAnalysisResetService.php`
**Tests:** `tests/Feature/DocumentAnalysisStateServiceTest.php`,
`tests/Feature/DocumentAnalysisResetServiceTest.php`

### 6.1 `DocumentAnalysisStateService`

The single writer of the lifecycle columns. Methods are small and guard the canonical invariants;
Phase 03's progress reporter will compose them.

```php
final class DocumentAnalysisStateService
{
    /** pending → processing; stamps analysis_started_at once. Idempotent. */
    public function start(ResearchedDocument $document): void;

    /** Move to a step; progress must be monotonic and within 0..100. */
    public function advance(ResearchedDocument $document, AnalysisStep $step, int $progress): void;

    /** processing/pending → completed; progress=100, step=completed, stamps completed_at. */
    public function complete(ResearchedDocument $document): void;

    /** Reset to pending/queued for a fresh run; used by retry. */
    public function resetToQueued(ResearchedDocument $document): void;

    /**
     * Non-terminal → failed with a safe message. No-op when the document is missing
     * or already terminal, so a late failure never overwrites a finished run.
     */
    public function fail(string $documentId, string $safeMessage): void;
}
```

Implementation rules:

- Use a short-lived model save (`$document->update([...])`), not raw `UPDATE`, so `updated_at`
  changes and the status endpoint sees movement.
- `advance()` clamps progress to `max(current, $progress)` and to `0..100`; it must never move a
  `completed` document backwards.
- `fail()` re-queries by id (the job deletes the document concurrently) and only transitions when
  the current status is not terminal.
- Bounded `analysis_error` (the caller passes a safe, pre-truncated message).

### 6.2 `DocumentAnalysisResetService`

Deletes the previous run's derived rows in FK-safe order. Does **not** delete the document or its
uploaded file. Called by retry and (in Phase 03) by `PersistExtractionStep` before inserting.

```php
final class DocumentAnalysisResetService
{
    public function reset(ResearchedDocument $document): void; // caller owns the transaction
}
```

Order (matches `02-document-lifecycle.md` §3.4):

```text
1. researched_document_citation_locations   (where citation_id in document citations)
2. researched_document_citations           (document scoped)
3. researched_document_reference_locations (where reference_id in document references)
4. reference_finding_candidates            (where reference_finding_id in document findings)
5. reference_findings                      (document scoped)
6. researched_document_references          (document scoped)
```

Implementation notes:

- Pluck ids with `->pluck('id')` and bulk `whereIn(...)->delete()`; do not hydrate full models.
- Deleting candidates before findings is harmless (`selected_candidate_id` is `nullOnDelete`).
- The method is intentionally explicit rather than relying on cascade, so a future relation that
  does not cascade cannot leave orphan rows; it stays engine-portable (SQLite/MySQL/Postgres).
- Idempotent: running on an already-empty document is a no-op.

### 6.3 Tests

`tests/Feature/DocumentAnalysisStateServiceTest.php`:

- `start()` sets `processing` + `analysis_started_at`; calling twice does not move `started_at`.
- `advance()` never decreases progress and clamps to `0..100`; records the step.
- `complete()` sets `completed`/`100`/`completed` step/`completed_at`.
- `resetToQueued()` clears error/progress/step/timestamps.
- `fail()` on a `processing` document sets `failed` + safe message; on a `completed` document it is
  a no-op; on a missing id it does not throw.

`tests/Feature/DocumentAnalysisResetServiceTest.php`:

- Build a `DocumentTree` with references (+locations), citations (+locations), findings
  (+candidates). Assert `reset()` removes all six row groups and leaves the document + `files` row
  intact.
- Running twice is a no-op.
- References with no finding and citations with no reference are handled.

---

## 7. T2 — Config, job seam and `AnalyzeDocumentJob`

**New files:** `config/analysis.php`, `app/Services/Analysis/Contracts/RunsDocumentAnalysis.php`,
`app/Jobs/AnalyzeDocumentJob.php`
**Modified:** `.env.example`
**Tests:** `tests/Feature/AnalyzeDocumentJobTest.php`

### 7.1 `config/analysis.php`

```php
return [
    // Queue the analysis job runs on. `docs/plans/backend/README.md` §10 default.
    'queue' => env('ANALYSIS_QUEUE', 'default'),

    // Hard ceiling for a single analysis run, seconds. Phase 03 adds `progress`.
    'timeout' => (int) env('ANALYSIS_TIMEOUT', 900),
];
```

`.env.example` — placeholders only:

```dotenv
# Document analysis queue.
ANALYSIS_QUEUE=default
ANALYSIS_TIMEOUT=900
```

Do **not** add `analysis.progress` or an inference preflight flag here; Phase 03 owns them.

### 7.2 The seam — `RunsDocumentAnalysis`

```php
namespace App\Services\Analysis\Contracts;

interface RunsDocumentAnalysis
{
    /**
     * Run the canonical analysis pipeline for a document and drive it to a
     * terminal state. Implemented by `App\Services\Analysis\AnalysisPipeline`
     * (Phase 03).
     */
    public function run(ResearchedDocument $document): void;
}
```

Rationale (D-02-01): the job is a stable transport envelope; the pipeline is a Phase 03
implementation detail. This mirrors the `ReportRenderer` seam decided for reports (OQ-05) and keeps
the queue contract out of the pipeline work. Phase 02 intentionally does **not** bind the
interface; every Phase 02 test that dispatches the job uses `Bus::fake()` so the container is never
asked to resolve it. Phase 03 binds `AnalysisPipeline` in a service provider.

> Cross-phase note: the broad `03-analysis-pipeline.md` §3.1 lists `AnalyzeDocumentJob` under its
> deliverables. This plan creates the envelope in Phase 02 because Phase 02 must dispatch it
> (T-DOC-09). Phase 03 owns `handle()`'s collaborator (the pipeline) and the queue middleware
> details (`WithoutOverlapping` tuning, progress). This is recorded in §12.2.

### 7.3 `AnalyzeDocumentJob`

```php
final class AnalyzeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;                 // deterministic single run; retry is a domain action
    public int $timeout;

    public function __construct(public readonly string $documentId)
    {
        $this->timeout = (int) config('analysis.timeout');
        $this->onQueue((string) config('analysis.queue'));
    }

    public function handle(RunsDocumentAnalysis $pipeline): void
    {
        $document = ResearchedDocument::query()->find($this->documentId);

        // Deleted while queued → abort quietly (Phase 03/T-PIPE-02 contract).
        if ($document === null) {
            return;
        }

        // Only a fresh/queued run may start; terminal or foreign states are ignored.
        if (! in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
            return;
        }

        $pipeline->run($document);
    }

    /**
     * Worker-level safety net (timeout, kill, unhandled resolution error). Phase 03's
     * in-pipeline handler covers step failures; this guarantees no document is stuck
     * in `processing`.
     */
    public function failed(?Throwable $exception): void
    {
        app(DocumentAnalysisStateService::class)->fail(
            $this->documentId,
            'Analisis dokumen gagal. Silakan coba lagi.',
        );
    }
}
```

Implementation notes:

- `SerializesModels` is unnecessary for a scalar id but harmless; the id-only payload is deliberate
  so a deleted model never breaks deserialization.
- `WithoutOverlapping` is **not** added here; the lock expiry and interaction with retry are tuned
  with the pipeline in Phase 03 (the broad plan assigns job uniqueness to Phase 03). The status
  guard above already makes a duplicate run harmless: a second run finding a terminal or
  `pending`-then-`processing` document is the pipeline's idempotency responsibility.
- `failed()` is required now so a job that cannot resolve `RunsDocumentAnalysis` (before Phase 03)
  still lands the document in a safe terminal state instead of leaving it `pending`.

### 7.4 Tests (`tests/Feature/AnalyzeDocumentJobTest.php`)

- Dispatch serializes only the document id: `new AnalyzeDocumentJob($id)->documentId === $id` and
  `onQueue`/timeout come from config.
- `handle()` returns quietly when the document is missing.
- `handle()` returns quietly for `completed`/`failed` documents (no pipeline call) — bind a fake
  `RunsDocumentAnalysis` in the container for this test (`$this->app->instance(...)`).
- `handle()` calls `run()` once for a `pending` document with a fake pipeline.
- `failed()` marks a `processing` document `failed` with the safe message; it does not overwrite a
  `completed` document.

---

## 8. T3 — Upload dispatch and canonical message

**Modified:** `app/Http/Controllers/Api/UploadController.php`,
`tests/Feature/UploadDocumentTest.php`
**No change** to `DocumentUploadService` here (the file-manager refactor is T8, kept separate so this
commit is small and the message/dispatch contract is isolated).

Changes:

1. Message → canonical `Dokumen berhasil diunggah. Analisis sedang diproses.`
   (`docs/API_SPEC.md` §4).
2. After `DocumentUploadService::handle()` returns (its transaction has committed), dispatch the
   job with after-commit semantics:

```php
$document = $this->documentUploadService->handle(...);
$document->load('file');

AnalyzeDocumentJob::dispatch($document->id)->afterCommit();

return ApiResponse::accepted(
    data: ResearchedDocumentDetailData::from($document),
    message: 'Dokumen berhasil diunggah. Analisis sedang diproses.',
);
```

`afterCommit()` is retained even though the service already commits, so a future caller that wraps
the upload in an outer transaction cannot dispatch before commit (README §6.4).

Upload never performs a synchronous inference preflight and never returns `503` (OQ-01 resolved); no
preflight code is added.

Test updates (`UploadDocumentTest`):

- Add `Bus::fake()` to every upload test.
- Keep all existing assertions; change the message assertion to the canonical string.
- Add T-DOC-09: `Bus::assertDispatched(AnalyzeDocumentJob::class, fn ($job) => $job->documentId === $documentId)`.
- Add a T-DOC-05-style assertion that no job is dispatched when validation fails (415/413/422) —
  `Bus::assertNothingDispatched()`.

---

## 9. T4 — DTOs, shared derived citation status, and summary service

**New files:**
`app/Data/ResearchedDocument/DocumentAnalysisSummaryData.php`,
`app/Data/ResearchedDocument/ResearchedDocumentSummaryData.php`,
`app/Data/ResearchedDocument/ResearchedDocumentStatusData.php`,
`app/Services/Citations/CitationStatusResolver.php`,
`app/Services/Document/DocumentSummaryService.php`
**Modified:** `app/Data/ResearchedDocument/ResearchedDocumentDetailData.php`
**Tests:** `tests/Unit/CitationStatusResolverTest.php`, `tests/Feature/DocumentSummaryServiceTest.php`

### 9.1 `DocumentAnalysisSummaryData` (exact §4 fields)

```php
#[MapName(SnakeCaseMapper::class)]
final class DocumentAnalysisSummaryData extends BaseData
{
    public int $totalReferences;
    public int $valid;
    public int $suspicious;
    public int $invalid;
    public int $notFound;
    public int $totalCitations;
    public int $validCitations;
    public int $unreliableCitations;
    public int $pendingCitations;
    public int $hallucinationCitations;
}
```

`fromCounts(...)` static constructor keeps the service readable and is also used by the consistency
test. `total_references` is the reference count; the four reference buckets are finding counts
(D-02-04).

### 9.2 `ResearchedDocumentSummaryData` (list item)

```php
#[MapName(SnakeCaseMapper::class)]
final class ResearchedDocumentSummaryData extends BaseData
{
    public string $id;
    public string $name;
    public DocumentStatus $status;
    #[MapInputName('analysis_progress')]
    public int $progress;
    public ?DocumentAnalysisSummaryData $summary = null;
    public ?CarbonImmutable $createdAt = null;
    public ?CarbonImmutable $updatedAt = null;

    public static function fromDocument(ResearchedDocument $document, ?DocumentAnalysisSummaryData $summary): self;
}
```

### 9.3 `ResearchedDocumentStatusData` (polling + retry response)

```php
#[MapName(SnakeCaseMapper::class)]
final class ResearchedDocumentStatusData extends BaseData
{
    public string $id;
    public DocumentStatus $status;
    #[MapInputName('analysis_progress')]
    public int $progress;
    #[MapInputName('analysis_step')]
    public ?AnalysisStep $currentStep = null;
    #[MapInputName('analysis_error')]
    public ?string $error = null;
    public ?CarbonImmutable $updatedAt = null;
}
```

### 9.4 Extend `ResearchedDocumentDetailData`

Add `public ?DocumentAnalysisSummaryData $summary = null;` and a factory that loads it explicitly
rather than relying on magic attribute mapping:

```php
public static function fromDocument(ResearchedDocument $document, ?DocumentAnalysisSummaryData $summary = null): self
{
    $data = self::from($document);
    $data->summary = $summary;

    return $data;
}
```

The upload response keeps calling `ResearchedDocumentDetailData::from($document)` — summary is
`null` for a fresh document and the field is additive.

### 9.5 `CitationStatusResolver` — one derivation, two renderings

```php
final class CitationStatusResolver
{
    /** PHP single source of truth; delegates to CitationStatus::derive(). */
    public function resolve(bool $isPaired, ?ReferenceFindingStatus $findingStatus): CitationStatus
    {
        return CitationStatus::derive($isPaired, $findingStatus);
    }

    /**
     * Portable SQL CASE yielding the derived status as its enum value string.
     * `$pairedColumn` is the citation's reference FK; `$findingStatusColumn` the
     * aliased finding status (nullable, must be LEFT JOINed).
     */
    public function sqlExpression(string $pairedColumn, string $findingStatusColumn): Expression;
}
```

The CASE is built from the enum values via bindings (no raw interpolation of user data), e.g.:

```sql
CASE
    WHEN <paired> IS NULL THEN 'hallucination'
    WHEN <finding_status> IS NULL OR <finding_status> = 'pending' THEN 'pending'
    WHEN <finding_status> IN ('valid','suspicious') THEN 'valid'
    ELSE 'unreliable'
END
```

Only the column identifiers are interpolated; the enum values are parameter bindings. Phase 05
reuses `sqlExpression()` for row status and filters.

### 9.6 `DocumentSummaryService`

```php
final class DocumentSummaryService
{
    public function forDocument(ResearchedDocument $document): ?DocumentAnalysisSummaryData; // null unless completed

    /**
     * Batched summaries for a page of documents. Only completed documents are present.
     *
     * @param  iterable<ResearchedDocument>  $documents
     * @return array<string, DocumentAnalysisSummaryData>  keyed by document id
     */
    public function forDocuments(iterable $documents): array;
}
```

Query design (portable `query()->selectRaw` + `groupBy`; no engine-specific SQL):

- **References:** `count(*)` grouped by `researched_document_id`.
- **Findings:** `status` + `count(*)` grouped by `researched_document_id`; fold into the four
  reference buckets.
- **Citations:** a single query over `researched_document_citations` LEFT JOIN
  `researched_document_references` LEFT JOIN `reference_findings`, using `CitationStatusResolver`
  bucket counts:

```sql
SELECT c.researched_document_id,
       COUNT(*) AS total_citations,
       SUM(CASE WHEN (<derived>) = 'hallucination' THEN 1 ELSE 0 END) AS hallucination,
       SUM(CASE WHEN (<derived>) = 'pending'       THEN 1 ELSE 0 END) AS pending,
       SUM(CASE WHEN (<derived>) = 'valid'         THEN 1 ELSE 0 END) AS valid,
       SUM(CASE WHEN (<derived>) = 'unreliable'    THEN 1 ELSE 0 END) AS unreliable
FROM researched_document_citations c
LEFT JOIN researched_document_references r ON r.id = c.researched_document_reference_id
LEFT JOIN reference_findings f ON f.researched_document_reference_id = r.id
WHERE c.researched_document_id IN (...)
GROUP BY c.researched_document_id
```

Rules:

- `forDocument()` returns `null` unless `$document->status === DocumentStatus::Completed` (OQ-10).
- `forDocuments()` filters to completed documents only; empty input returns `[]` without querying.
- `forDocuments()` issues exactly **three** queries regardless of document count (F-SUM-02).
- `in_array`/`whereIn` values are UUIDs from the caller (trusted, but still bound).

### 9.7 Tests

`tests/Unit/CitationStatusResolverTest.php` (F-CIT-06..09 reinforcement + F-SUM consistency):

- `resolve()` matches `CitationStatus::derive()` for all pairings/finding statuses.
- Consistency test: insert a small fixture (paired valid, paired invalid, paired pending, paired
  with no finding, unpaired) through `DocumentTree`, run the SQL expression and the PHP resolver,
  and assert the buckets are identical. This is the guard that keeps the SQL from drifting.

`tests/Feature/DocumentSummaryServiceTest.php` (F-SUM-01/02):

- Fixture: 1 reference valid + 1 suspicious + 1 invalid + 1 not_found; citations covering all four
  derived buckets. Assert exact counts.
- A reference with no finding is counted in `total_references` but in none of the four buckets.
- `forDocument()` returns `null` for pending/processing/failed.
- `forDocuments()` returns only completed documents and its query count is constant for 1 vs N
  documents (`DB::enableQueryLog()`/`DB::getQueryLog()` length assertion).
- `forDocuments([])` returns `[]` with zero queries.

---

## 10. T5 — List request and query service

**New files:** `app/Http/Requests/Document/ListDocumentsRequest.php`,
`app/Services/Document/DocumentQueryService.php`
**Tests:** `tests/Feature/DocumentQueryServiceTest.php`

### 10.1 `ListDocumentsRequest`

```php
public function rules(): array
{
    return [
        'status'   => ['nullable', Rule::enum(DocumentStatus::class)],
        'q'        => ['nullable', 'string', 'max:255'],
        'sort'     => ['nullable', Rule::in(['created_at', '-created_at'])],
        'page'     => ['nullable', 'integer', 'min:1'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ];
}

public function defaultSort(): string { return '-created_at'; }
public function perPage(): int { return (int) ($this->validated('per_page') ?? 15); }
```

Invalid enum/sort/direction → the canonical `422 VALIDATION_ERROR` via the Phase 01 renderer (no
custom `failedValidation`).

### 10.2 `DocumentQueryService`

```php
final class DocumentQueryService
{
    public function paginate(User $user, ListDocumentsRequest $request): LengthAwarePaginator;
}
```

- `ResearchedDocument::query()->forUser($user)`.
- `status` filter → `where('status', $request->validated('status'))`.
- `q` filter → escaped `LIKE` against `name`; escape `%`, `_` and `\` in the term so a user cannot
  inject wildcards:
  `where('name', 'like', '%'.addcslashes($term, '%_\\').'%')`.
- Deterministic order: `orderBy($column, $direction)->orderBy('id', $direction)` where `$column` is
  `created_at` (never user-controlled beyond the whitelist).
- `paginate($request->perPage())->withQueryString()`.
- **No** relation eager-loading for the list (the summary DTO has no embedded relations).

### 10.3 Tests

- Default order is `-created_at` with `id` descending tiebreak; explicit `created_at` reverses both.
- `status` and `q` filter correctly; `q` is case-insensitive for the SQLite test engine and does not
  treat `%` as a wildcard.
- `per_page` default 15 / max 100; `per_page=101` → `422` at the HTTP layer (covered in T9).
- Pagination is stable: two documents sharing a `created_at` appear once each across pages
  (tiebreaker assertion).
- Query only returns the authenticated user's documents (`forUser` scope).

---

## 11. T6 — `DocumentController` (index/show/status) and routes

**New file:** `app/Http/Controllers/Api/DocumentController.php`
**Modified:** `routes/api.php`
**Tests:** `tests/Feature/DocumentEndpointTest.php`

### 11.1 Controller

```php
final class DocumentController extends Controller
{
    // T6 constructor: only the collaborators the read actions need. T7 adds
    // `DocumentLifecycleService` when `retry()` lands; T8 adds `DocumentDeletionService`
    // when `destroy()`/`purge()` land — so no commit references a not-yet-created class.
    public function __construct(
        private readonly DocumentQueryService $queryService,
        private readonly DocumentSummaryService $summaryService,
        private readonly OwnedResourceFinder $finder,
    ) {}

    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $paginator = $this->queryService->paginate($request->user(), $request);

        $summaries = $this->summaryService->forDocuments($paginator->items());

        $paginator->through(
            fn (ResearchedDocument $document): ResearchedDocumentSummaryData
                => ResearchedDocumentSummaryData::fromDocument($document, $summaries[$document->id] ?? null),
        );

        return ApiResponse::collection($paginator, ResearchedDocumentSummaryData::class);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);
        $model->load('file');

        return ApiResponse::single(
            ResearchedDocumentDetailData::fromDocument($model, $this->summaryService->forDocument($model)),
        );
    }

    public function status(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        return ApiResponse::single(ResearchedDocumentStatusData::from($model));
    }
}
```

Design rules:

- `index`: `through()` converts items to `ResearchedDocumentSummaryData`, so
  `ApiResponse::collection` passes them straight through (already `Data`). This avoids re-mapping
  and keeps the minute the summary was computed consistent with `meta`.
- `status` must be cheap: the finder lookup is a single indexed row; `ResearchedDocumentStatusData`
  reads only the six columns present on the model. **Do not** load `file` or compute a summary.
  (If a future optimization needs a column subset, add a dedicated repository method — not a
  relation load.)
- `show` loads `file` for the embedded `FilePreviewData` and computes the summary only when
  completed (service enforces it).
- Route model binding is **not** used; the UUID is validated by `whereUuid` and resolved through the
  finder so ownership and the per-resource 404 message are preserved.

### 11.2 Routes

Add inside the existing `['auth:sanctum', 'throttle:api']` group, naming `v1.documents.*`:

```php
Route::middleware('throttle:documents')->group(function (): void {
    Route::post('/documents', [UploadController::class, 'upload'])->name('documents.store');
});

// Static purge route declared before the wildcard sibling, never shadowed.
Route::delete('/documents', [DocumentController::class, 'purge'])->name('documents.purge');

Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');

Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->whereUuid('document')->name('documents.show');

Route::get('/documents/{document}/status', [DocumentController::class, 'status'])
    ->middleware('throttle:document-status')->whereUuid('document')->name('documents.status');

Route::post('/documents/{document}/retry', [DocumentController::class, 'retry'])
    ->whereUuid('document')->name('documents.retry');       // T7

Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
    ->whereUuid('document')->name('documents.destroy');     // T8
```

`DocumentController` methods `retry`/`destroy`/`purge` land in T7/T8; T6 creates the controller with
the three read actions and the routes that exist at that commit. To keep T6 self-contained, add
`retry`/`destroy`/`purge` as their tasks arrive, each in its own commit, so `routes/api.php` never
references a missing method.

### 11.3 Tests (`tests/Feature/DocumentEndpointTest.php`)

- T-DOC-10: list returns `data` + `meta` (`current_page`/`per_page`/`total`/`last_page`); default
  sort `-created_at`; `status`/`q` filters; invalid `status`/`sort`/`per_page` → `422`.
- T-DOC-11: detail for a completed document includes the exact summary counts;
  pending/processing/failed → `summary: null`; detail includes `file`.
- T-DOC-12: status payload has exactly `id/status/progress/current_step/error/updated_at` (assert
  `assertJsonStructure` and assert the absence of `file`/`summary`).
- `index` summary comes from the batch path and equals the single-document summary for the same
  fixture (guards drift between the two code paths, `02-document-lifecycle.md` §7).
- Pagination meta for a seeded set (e.g. 20 docs → `total=20`, default `last_page=2`).

---

## 12. T7 — Retry endpoint

**New files:** `app/Services/Document/DocumentLifecycleService.php`
**Modified:** `app/Http/Controllers/Api/DocumentController.php`, `routes/api.php`
**Tests:** `tests/Feature/DocumentRetryTest.php`

### 12.1 `DocumentLifecycleService`

```php
final class DocumentLifecycleService
{
    public function __construct(
        private readonly DocumentAnalysisResetService $resetService,
        private readonly DocumentAnalysisStateService $stateService,
    ) {}

    /**
     * Re-run the pipeline for a failed document.
     *
     * @throws StateConflictException when the document is not `failed`.
     */
    public function retry(ResearchedDocument $document): ResearchedDocument
    {
        if (! $document->status->allowsRetry()) {
            throw StateConflictException::documentRetryNotAllowed();
        }

        DB::transaction(function () use ($document): void {
            $this->resetService->reset($document);
            $this->stateService->resetToQueued($document);
        });

        AnalyzeDocumentJob::dispatch($document->id)->afterCommit();

        return $document->refresh();
    }
}
```

Rules:

- The guard throws **before** any write; the spec message
  `"Analisis hanya dapat diulang untuk dokumen yang gagal."` comes from the Phase 01 exception.
- Reset + state reset share one transaction so a crash cannot leave derived rows half-deleted.
- The uploaded file is **not** touched.
- Dispatch is after commit so the worker cannot see a half-reset document.

### 12.2 Controller action + route

```php
public function retry(Request $request, string $document): JsonResponse
{
    $model = $this->finder->document($request->user(), $document);

    $fresh = $this->lifecycleService->retry($model);

    return ApiResponse::accepted(
        data: ResearchedDocumentStatusData::from($fresh),
        message: 'Analisis dijadwalkan ulang.',
    );
}
```

Route added in T6's block (see §11.2). Also add `DocumentLifecycleService` to the
`DocumentController` constructor in this commit.

### 12.3 Tests (`tests/Feature/DocumentRetryTest.php`)

- T-DOC-13: retry from `failed` → `202`, `status=pending`, `progress=0`, `current_step=queued`,
  message exact, `Bus::assertDispatched(AnalyzeDocumentJob::class)` with the right id, and the
  previous references/citations/findings/candidates/locations are gone while the `files` row
  remains.
- T-DOC-14: retry from `pending`/`processing`/`completed` → `409 CONFLICT` + exact message; no job
  dispatched; no rows deleted.
- A second retry after the first flips the document to `pending` → `409` (the guard is the only
  concurrency protection Phase 02 needs; `WithoutOverlapping` arrives in Phase 03).
- Retry dispatch uses `afterCommit` semantics: assert with `Bus::fake()` inside a wrapping
  transaction that nothing is dispatched until commit (or assert the job is dispatched after the
  service returns).

---

## 13. T8 — File manager, deletion and purge

**New file:** `app/Services/Document/DocumentFileManager.php`
**Modified:** `app/Services/DocumentUploadService.php`,
`app/Services/Document/DocumentDeletionService.php` (new),
`app/Http/Controllers/Api/DocumentController.php`, `routes/api.php`
**Tests:** `tests/Feature/DocumentDeletionTest.php`, existing `UploadDocumentTest` stays green

### 13.1 `DocumentFileManager` (shared with Phase 06)

```php
final class DocumentFileManager
{
    /** Store an uploaded file for a morph owner and create its `files` row. */
    public function store(Model $owner, UploadedFile $file, string $filename): File;

    /** Delete the stored object (best-effort) and its `files` row. */
    public function delete(File $file): void;

    /**
     * Collect and delete every `files` row belonging to a document or its reports,
     * returning the stored paths to delete after the DB commit.
     *
     * @return list<string>
     */
    public function detachForDocument(ResearchedDocument $document): array;
}
```

Design:

- `store()` uses the document-scoped directory `documents/{ownerId}` and `hashName()`, matching the
  current `DocumentUploadService` behavior; it returns the created `File`.
- `delete()` removes the object first (swallow + log a storage failure) then the row; callers that
  need atomicity delete rows inside a transaction and objects after commit.
- `detachForDocument()` collects: the document's own morph `files` rows **plus** `files` rows whose
  `fileable_type = 'generated_document_report'` and `fileable_id` is in the document's report ids
  (and any report `file_id`). It deletes the rows and returns their paths. Because `files` has no
  FK, this is the only reliable cleanup.
- The morph alias strings are read from the enforced morph map
  (`Relation::getMorphAlias(...)`), never hardcoded.

`DocumentUploadService` refactor:

- `createDocument()` unchanged.
- Replace the inline `storeAs` + `file()->create` with `$this->fileManager->store($document, $file, $file->getClientOriginalName())` inside the same transaction.
- Preserve the try/catch: on failure delete the stored path if it was created, rethrow
  `DocumentUploadFailedException` (`storageFailed`/`persistenceFailed`). `DocumentUploadTest`
  (including the 500 storage/DB failure path T-DOC-08) is the regression guard.

### 13.2 `DocumentDeletionService`

```php
final class DocumentDeletionService
{
    public function delete(ResearchedDocument $document): void;

    /** Delete every document owned by the user, chunked. Returns the count. */
    public function purge(User $user): int;
}
```

`delete()`:

```text
1. $paths = $fileManager->detachForDocument($document);   // collects + deletes `files` rows
2. DB::transaction: delete the document (FK cascade removes references, locations,
   citations, findings, candidates, reports)
3. after commit: delete each stored object best-effort; log failures (never silently leave
   orphans — surface via the log with document id)
```

`purge()`:

- Iterate `$user->researchedDocuments()->lazyById()` and call `delete()` per document. Never
  materialise the whole history (D-02-08).
- Returns the deleted count (useful in tests; not part of the public response — `204` has no body).

Concurrency note: a running job may race a deletion. The job's `handle()` re-queries the document
and aborts if missing (T1), so the worst case is a step that fails and logs; Phase 03's failure
handler treats a missing document as a quiet abort.

### 13.3 Controller actions + routes

```php
public function destroy(Request $request, string $document): Response
{
    $this->deletionService->delete($this->finder->document($request->user(), $document));

    return ApiResponse::noContent();
}

public function purge(Request $request): Response
{
    $this->deletionService->purge($request->user());

    return ApiResponse::noContent();
}
```

Routes added to the T6 block. Also add `DocumentDeletionService` to the
`DocumentController` constructor in this commit.

### 13.4 Tests (`tests/Feature/DocumentDeletionTest.php`)

- T-DOC-15: `DELETE /documents/{id}` → `204`, empty body; document + references + locations +
  citations + findings + candidates + reports gone; the `files` row is gone and the stored object
  no longer exists (`Storage::fake`).
- Report file cleanup: seed a report with a `file_id`/polymorphic file, delete the document, assert
  both the report and its file row/object are gone.
- T-DOC-16: purge → `204`; only the authenticated user's documents are removed; another user's
  documents, files and objects are untouched.
- Purge on an empty history → `204` (no error).
- Purge does not load the whole history: chunked caller asserted via query log count or a spy on
  `delete()` per document.
- A storage failure on object deletion does not fail the request (row already deleted); the failure
  is logged (`Log::spy()`).

---

## 14. T9 — Rate limits, ownership isolation, and end-to-end lifecycle coverage

**Modified:** `tests/Feature/RateLimitersTest.php`,
`tests/Feature/OwnershipIsolationTest.php`, `tests/Feature/DocumentEndpointTest.php`

### 14.1 Route-level rate limits (T-DOC-17, `RateLimitersTest`)

- `POST /documents` 11× as the same user within a minute → the 11th returns `429 RATE_LIMITED`
  with the canonical envelope. Use `RateLimiter::clear` in `beforeEach` or a fresh user per test.
- `GET /documents/{id}/status` 121× → `429`. Keep the loop cheap by hitting an owned document and
  asserting only the final response.
- Assert the `429` body matches `docs/API_SPEC.md` §2.9 and that a different user is unaffected.

### 14.2 HTTP ownership isolation (T-OWN-01..04, extend `OwnershipIsolationTest`)

Add HTTP cases (the file already builds an owner + a foreign user and asserts the finder service):

- `GET /documents/{other}`, `GET /documents/{other}/status`, `POST /documents/{other}/retry`,
  `DELETE /documents/{other}` → `404 NOT_FOUND`, `error.code = NOT_FOUND`, message
  `Dokumen tidak ditemukan.`
- The body for a foreign id is byte-identical to the body for a random UUID (T-OWN-09): capture both
  responses and compare `json()`.
- T-OWN-09 for purge: user B purging their own history never removes user A's rows.

### 14.3 Lifecycle end-to-end smoke

- One test walks upload → list (pending, summary null) → status → (factory-mutate to completed) →
  show (summary present) → retry rejected (409) → delete (204). This is the "surface works together"
  guard, not a pipeline test.

---

## 15. T10 — Documentation sync and final validation

- [ ] `docs/ARCHITECTURE.md` §13 — backend bullet: `/documents` lifecycle endpoints, summary/status
      DTOs, `AnalyzeDocumentJob` dispatch, state/reset/deletion services.
- [ ] `AGENTS.md` §14 — same status update (list the new endpoints/services; keep the "missing"
      list accurate: pipeline internals, inference/Crossref clients, references/citations/findings,
      reports).
- [ ] `docs/plans/backend/README.md` §3.1/§3.2 — move `/documents` list/detail/status/retry/delete/
      purge and the job-dispatch seam from "missing" to "exists"; update the phase-map/status rows
      if present.
- [ ] `docs/plans/backend/02-document-lifecycle.md` — status header points at this detailed plan.
- [ ] Confirm **no** `docs/API_SPEC.md` / `docs/DB_SCHEMA.md` change is required. If a task needed
      one, it was a contract change and must be called out (README §2/§9.6).
- [ ] `php artisan test --compact` (full suite) + `vendor/bin/pint --dirty --format agent`.

---

## 16. Acceptance matrix

| ID | Case | Covered by |
|---|---|---|
| T-DOC-01/02 | Upload ≤ 20 MB / custom name | `UploadDocumentTest` (existing, message updated) |
| T-DOC-03..08 | 415 / 413 / 422 / 401 / storage failure | `UploadDocumentTest` (existing, must stay green) |
| T-DOC-09 | `AnalyzeDocumentJob` dispatched on upload | `UploadDocumentTest` + `Bus::fake()` |
| T-DOC-10 | List filters/sort/pagination | `DocumentEndpointTest` |
| T-DOC-11 | Detail includes `summary` only when completed | `DocumentEndpointTest` |
| T-DOC-12 | Status payload exact shape | `DocumentEndpointTest` |
| T-DOC-13 | Retry from `failed` resets + dispatches | `DocumentRetryTest` |
| T-DOC-14 | Retry from non-`failed` → `409` | `DocumentRetryTest` |
| T-DOC-15 | Delete one + cascade + file cleanup | `DocumentDeletionTest` |
| T-DOC-16 | Purge only the owner's history | `DocumentDeletionTest` |
| T-DOC-17 | Upload/status rate limits | `RateLimitersTest` |
| T-OWN-01..04 | Foreign show/status/retry/delete → `404` | `OwnershipIsolationTest` |
| T-OWN-09 | Foreign vs missing body indistinguishable; purge isolation | `OwnershipIsolationTest` |
| F-SUM-01 | Summary counts exact | `DocumentSummaryServiceTest` |
| F-SUM-02 | List summary is batched (constant query count) | `DocumentSummaryServiceTest` |
| F-CIT consistency | SQL buckets == `CitationStatus::derive()` | `tests/Unit/CitationStatusResolverTest.php` |
| Job seam | `handle` guard + `failed()` safety net | `tests/Feature/AnalyzeDocumentJobTest.php` |
| State/reset | State invariants + FK-safe reset | `DocumentAnalysisStateServiceTest`, `DocumentAnalysisResetServiceTest` |

---

## 17. Risks, pitfalls and deliberate deviations

### 17.1 Pitfalls to avoid

1. **Dispatching inside the transaction.** Always `->afterCommit()`; the upload service owns the
   transaction and the retry service wraps reset in its own.
2. **`index` double-mapping.** `through()` already returns `ResearchedDocumentSummaryData` items;
   `ApiResponse::collection` must receive them as-is (pass-through branch). Assert `data.0.id`, not
   `data.data.0.id`.
3. **Status endpoint cost.** Never `load('file')` or compute the summary in `status()`.
4. **Purge while iterating.** `lazyById()` is safe only because it advances by `id >`; do not use
   `chunk()` with an offset while deleting rows (rows shift and get skipped).
5. **Polymorphic file cleanup.** `files` has no cascade; deleting the document alone leaves the
   `files` row and the object. Collect rows **before** the DB delete (T8).
6. **`nullOnDelete` citations.** Deleting references unpairs citations instead of deleting them;
   the reset service deletes citations first.
7. **`total_references` vs bucket sum.** They are intentionally not required to sum; do not add a
   phantom `pending` bucket that the spec does not define.
8. **SQLite vs MySQL/Postgres aggregates.** Use `selectRaw` with bindings and `SUM(CASE ...)`;
   avoid `FILTER (WHERE ...)` and engine-specific functions.
9. **Unbound `RunsDocumentAnalysis`.** Phase 02 tests must `Bus::fake()` any dispatch. A test that
   lets the sync queue execute will try to resolve the interface and fail — that is intentional
   fail-loud behavior, not a bug; Phase 03 binds the implementation.
10. **Search wildcards.** Escape `%`/`_` in `q` so a user cannot broaden the `LIKE`.
11. **`created_at` ties.** Always append the `id` tiebreaker so pagination cannot drop or duplicate
    rows.
12. **`summary` type.** Use `float`/`int` casts as Phase 01 established; never `decimal:*` (string
    output) for counts.

### 17.2 Deliberate deviations from the broad plan (accepted, documented)

1. **D-02-01** — `AnalyzeDocumentJob` envelope is created in Phase 02 (it must be dispatchable);
   Phase 03 owns its pipeline collaborator. The broad `03-analysis-pipeline.md` lists the job under
   Phase 03; this plan resolves the contradiction explicitly in favour of dispatch in Phase 02.
2. **`DocumentAnalysisStateService`** centralises state writes in Phase 02, even though progress is
   Phase 03. The alternative — retry/`failed()` writing columns directly and Phase 03 writing them
   again — duplicates the invariant guards.
3. **`CitationStatusResolver`** ships both the PHP and SQL renderings of one rule in Phase 02, with
   a consistency test, instead of Phase 02 hand-writing summary buckets and Phase 05 later
   extracting them. This is the "never duplicate the rule" path the broad plan asked for.
4. **Retry reuses `ResearchedDocumentStatusData`** (D-02-05) rather than a fourth DTO for four
   fields; extra `error`/`updated_at` keys are additive.
5. **`WithoutOverlapping` is not added in Phase 02.** The status guard makes duplicate runs safe;
   the lock's expiry and retry interaction belong with the pipeline (Phase 03).
6. **No inference preflight** on upload (OQ-01): upload always `202`; no `analysis.require_inference_at_upload`
   config key is added while it would be dead config. If the opt-in is ever wanted, it arrives with
   Phase 03's inference client.

### 17.3 Rollback

Each task is a self-contained commit; there is no migration and no backfill in this phase. Reverting
a commit removes its routes/controller actions/services with no data implications. The
`DocumentUploadService` refactor (T8) is the only change to working code and is covered by the
existing `UploadDocumentTest`; revert it together with `DocumentFileManager`.

---

## 18. Hand-off contract for Phases 03+

Phase 03 may rely on, and must not duplicate:

| Primitive | Location | Usage rule |
|---|---|---|
| Job envelope | `App\Jobs\AnalyzeDocumentJob` | Dispatched with `->afterCommit()`; add `WithoutOverlapping`/progress here, not a second job |
| Pipeline seam | `App\Services\Analysis\Contracts\RunsDocumentAnalysis` | Bind `AnalysisPipeline` to it in Phase 03; the job stays unchanged |
| State writes | `App\Services\Document\DocumentAnalysisStateService` | Phase 03 progress goes through `advance()`/`complete()`/`fail()` |
| Derived-row reset | `App\Services\Document\DocumentAnalysisResetService` | `PersistExtractionStep` calls it before insert for idempotency |
| Derived citation rule | `CitationStatusResolver` (+ `CitationStatus::derive()`) | Phase 05 row status, filters and findings feed reuse `sqlExpression()`/`resolve()` |
| Summary counts | `App\Services\Document\DocumentSummaryService` | `FinalizeAnalysisStep`/`generating_report` may call it; never recompute inline |
| File lifecycle | `App\Services\Document\DocumentFileManager` | Phase 06 reports reuse `store()`/`delete()`; extend it rather than writing files inline |
| Ownership | `OwnedResourceFinder` | All new document-owned endpoints resolve through it |
| Config | `config/analysis.php` | Phase 03 adds `progress`; Phase 02 owns `queue`/`timeout` |

Also carried forward as explicit Phase 03 requirements: bind `RunsDocumentAnalysis` to the concrete
`AnalysisPipeline`; add `WithoutOverlapping` and the monotonic progress map; make
`PersistExtractionStep` idempotent via the reset service; and treat `ModelNotFoundException` from a
document deleted mid-run as a quiet abort.
