# Phase 03 — Analysis Pipeline & Inference Client

> **Status:** broad plan — **implemented**; see [`03-analysis-pipeline-detail.md`](03-analysis-pipeline-detail.md)
> for the executable plan (all recommendations/defaults adopted there). · **Depends on:** Phase 02 · **Unblocks:** Phase 04
> Canonical references: `docs/API_SPEC.md` §2.6/§9/§10, `docs/ARCHITECTURE.md` §7/§8/§10,
> `docs/TEST_PLAN.md` T-PIPE / T-INF, `AGENTS.md` §8.

---

## 1. Objective

Turn `POST /documents` into a real asynchronous pipeline: a queue job that walks the canonical
analysis steps, calls the internal FastAPI service for extraction, persists the extracted
references/citations/locations, reports monotonic progress, and lands the document in a terminal
state with a safe, user-facing error when it fails.

Crossref validation/scoring (Phase 04) and citation resolution (Phase 05) plug into the same step
machine; this phase defines the machine, the inference client and the persistence contract.

---

## 2. Scope

**In:** `AnalyzeDocumentJob`, pipeline runner and step contract, progress/state transitions,
inference HTTP client + DTOs, extraction persistence/normalization, failure handling, job
idempotency/uniqueness, extraction tests with faked inference.

**Out:** Crossref lookups and scoring (Phase 04), citation resolution (Phase 05), report
generation (Phase 06), inference service implementation (separate plan, OQ-13).

---

## 3. Deliverables

### 3.1 Job and pipeline

- `App\Jobs\AnalyzeDocumentJob` (`ShouldQueue`), dispatched from `UploadController` and
  `DocumentLifecycleService::retry` with `afterCommit()`.
- `App\Services\Analysis\AnalysisPipeline` — runs ordered steps, owns the transition to
  `processing`/`completed`, and orchestrates progress.
- `App\Services\Analysis\Steps\*` — one class per step, each with a narrow contract, e.g.
  `handle(ResearchedDocument $document): void`; steps communicate through the database (not by
  passing large payloads), so a failed run is always resumable from persisted state.
- `App\Services\Analysis\AnalysisProgress` — monotonic progress reporter.
- `App\Services\Analysis\AnalysisFailureHandler` — maps exceptions to a safe `analysis_error` and
  the `failed` transition.

Step sequence (canonical `analysis_step` order, `docs/API_SPEC.md` §2.6):

```text
queued
  → extracting            ExtractDocumentStep        (Phase 03)
  → persisting            PersistExtractionStep      (Phase 03)
  → crossref_validation   ValidateReferencesStep     (Phase 04)
  → embedding             EmbedReferencesStep        (Phase 04)
  → scoring               ScoreReferencesStep        (Phase 04)
  → resolving_citations   ResolveCitationsStep       (Phase 05)
  → generating_report     FinalizeAnalysisStep       (Phase 03; bookkeeping only)
  → completed
```

`generating_report` is a bookkeeping/finalization step only — reports are on-demand
(`POST /documents/{document}/reports`, Phase 06); it must not generate a PDF inline (OQ-08
resolved).

### 3.2 Job semantics

- `$tries = 1` (deterministic single run; domain-level retry is the API contract). Queue-level
  retries would make `failed`/progress semantics ambiguous — do not enable them.
- `$timeout` from config (`analysis.timeout`, default ≈ 15 min) and
  `WithoutOverlapping("document-analysis:{$id}")` with an expiry bound.
- Status guard at entry:
  - document missing (deleted while queued) → abort quietly;
  - `status` not in `{pending, processing}` → abort (retry API already enforces `failed`).
- Progress updates use a short-lived model save (not a raw `UPDATE`) so `updated_at` changes and
  polling sees movement. Batch writes (e.g. every 10 items or 5% change) to avoid write storms.
- Every step must be idempotent: re-running the job (after a crash) starts from a clean derived
  state. `PersistExtractionStep` calls the Phase 02 `DocumentAnalysisResetService` before
  inserting.

### 3.3 Failure handling

- `AnalysisFailureHandler::handle($document, Throwable $e, AnalysisStep $step)`:
  - transition to `failed`, keep `analysis_progress` where it stopped, set `analysis_error` to a
    **safe user-facing** message, never a stack trace;
  - log the full exception with document id, step and a correlation id;
  - only transition when the document currently exists and is not terminal.
- Suggested safe messages (detail in the phase plan):
  - extract/inference unavailable → `"Layanan analisis tidak tersedia. Coba lagi nanti."`
  - Crossref entirely unreachable → `"Validasi Crossref tidak tersedia. Coba lagi nanti."`
    (OQ-03 resolved: a total outage fails the document)
  - unexpected → `"Analisis dokumen gagal. Silakan coba lagi."`
- Worker timeout/kill must also reach `failed` (Laravel calls `failed()`), so a document never
  stays `processing` forever from a crashed job.

### 3.4 Inference client — `app/Services/Inference/`

- `InferenceClient` (internal only, never routed publicly):
  - `health(): HealthData` → `GET /health` (only for the opt-in OQ-01 preflight, disabled by
    default);
  - `extract(string $storagePath): ExtractionResultData` → multipart `POST /v1/extract`;
  - `embeddings(array $texts): EmbeddingResultData` → `POST /v1/embeddings`, batched by
    `services.inference.embedding_batch_size` (default 32).
- DTOs mirroring `docs/API_SPEC.md` §9 exactly:
  `ExtractionResultData`, `ExtractedReferenceData`, `ExtractedCitationData`,
  `ExtractedLocationData`, `HealthData`, `EmbeddingResultData`.
- Behaviour:
  - config-driven base URL, connect/read timeouts;
  - connection errors, timeouts and 5xx → `InferenceUnavailableException` (503 semantics);
  - 4xx/422 from `/v1/extract` (unparseable PDF) → a distinct `ExtractionFailedException`
    (fatal for the document, safe message);
  - never log or expose the raw response body to the user;
  - supports `Http::fake()` in tests (no retries on 4xx, bounded retries only where safe).
- The client reads the PDF from the private disk itself (given the stored path); it must not
  depend on the HTTP layer/request.

### 3.5 Extraction persistence — `App\Services\Analysis\PersistExtractionStep`

Mapping to the canonical tables:

| Payload (`API_SPEC.md` §9) | Table | Notes |
|---|---|---|
| `references[]` | `researched_document_references` | `raw_text`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, offsets |
| `references[].locations[]` | `..._reference_locations` | 1-based `page_number`, bbox, `coordinate_system`, `location_index` |
| `citations[]` | `researched_document_citations` | text, marker, contexts, offsets, `occurrence_index` |
| `citations[].locations[]` | `..._citation_locations` | same location contract (`citation_id` column) |

Normalization rules:

- `location_index`: unique per parent, 0..N-1 in payload order; reindex if the service returns
  duplicates (unique index would otherwise fail the run).
- `occurrence_index`: unique per document (`(researched_document_id, occurrence_index)` unique);
  if the payload has duplicates/nulls, order by `text_start_offset` then payload order and
  reassign 0..N-1.
- Normalize DOIs (delegated to the Phase 04 `DoiNormalizer`, or a temporary local equivalent) so
  storage is consistent.
- Validate shapes (page ≥ 1, numeric bbox, bounded string lengths); a malformed internal payload
  is a fatal extraction error, not a silent skip.
- Bulk insert with app-generated UUIDs and timestamps inside one transaction; keep the whole
  document's extraction in a single transaction per step so a failure leaves no half state.
- `reference_index` from the payload is **not persisted** (no column); it is consumed by Phase 05
  through the extraction result passed in memory or re-resolved from persisted data. Decide the
  hand-off in the detailed plan (recommendation: Phase 05 re-derives from markers/ordered
  references, so the pipeline needs no extra column).

### 3.6 Progress map (configurable, monotonic)

| Step | Floor → ceiling |
|---|---|
| `queued` | 0 |
| `extracting` | 5 → 25 |
| `persisting` | 25 → 35 |
| `crossref_validation` | 35 → 60 |
| `embedding` | 60 → 70 |
| `scoring` | 70 → 85 |
| `resolving_citations` | 85 → 95 |
| `generating_report` | 95 → 99 |
| `completed` | 100 |

Store the map in config (`analysis.progress`) so it can be tuned; `AnalysisProgress` enforces
monotonicity (never decrease, never exceed the step ceiling) and asserts 100 only on completion.

### 3.7 Config

- `analysis.queue` (queue name; recommendation: a dedicated `analysis` queue so report jobs do
  not compete), `analysis.timeout`, `analysis.progress`.
- `services.inference.*` (created in Phase 01).
- `.env.example` placeholders only.

---

## 4. Contracts & invariants

- Upload/retry never run the pipeline synchronously; the queue owns it.
- Step sequence and values are canonical; progress is monotonic 0→100.
- `analysis_error` is user-facing and safe; raw exceptions live only in logs.
- A terminal state is reached on every path (completed or failed). No stuck `processing`.
- `completed` means the pipeline finished, findings included.
- The inference service is internal; no public route or frontend access.

---

## 5. Tests / acceptance criteria

| Test ID | Case | Expected |
|---|---|---|
| T-PIPE-01 | Full job with faked inference + faked Crossref | rows persisted, `completed`, `progress=100`, step `completed` |
| T-PIPE-02 | GROBID/extract failure | `failed` + safe `analysis_error` |
| T-PIPE-03 | Crossref partial failure | per-reference handling recorded, pipeline continues (Phase 04) |
| T-PIPE-04 | Progress monotonicity | never decreases; reaches 100 only with `completed` |
| T-PIPE-05 | Upload does not run synchronously | `Bus::fake()` assertion / response independent of pipeline |
| T-INF-BE-01 | `/v1/extract` contract fixture | client maps payload to DTOs, persists expected rows |
| T-INF-BE-02 | `/v1/embeddings` batching | batches of ≤ configured size, dimensions validated |
| T-INF-BE-03 | Inference 5xx/timeout/connection error | `InferenceUnavailableException`; document `failed` with safe message |
| T-INF-BE-04 | Unparseable PDF (422) | fatal extraction failure, safe message |
| F-PIPE-01 | Job re-run after partial failure | reset + idempotent persistence (no unique-index violations) |
| F-PIPE-02 | Document deleted while job queued | job aborts quietly, no exception noise |
| F-PIPE-03 | Worker timeout | document ends `failed`, not stuck in `processing` |

Exit: pipeline can drive a document to `completed` with fakes, and every failure path lands in
`failed` with a safe message.

---

## 6. Decisions (resolved)

- **OQ-01** — `health()` exists but the upload preflight stays disabled by default.
- **OQ-03** — a total Crossref outage fails the document; per-reference transient failures become
  `pending` with an explicit reason (Phase 04 consumes this).
- **OQ-04** — embedding unavailability degrades to string signals; extraction failure stays fatal.
- **OQ-08** — `generating_report` is bookkeeping only.
- **OQ-13** — the real inference service is a separate plan; this backend uses a fixture-driven
  fake in `tests/Support` (no production stub).

---

## 7. Risks

- Long-running jobs with many references can hit worker timeouts; keep per-step transactions short,
  batch DB writes, and rely on the domain retry path. Consider chunked per-reference processing in
  the detailed plan.
- Extraction payload drift between backend and inference service; the contract test fixtures are
  the guard. Update `docs/API_SPEC.md` §9 first for any shape change.
- Reindexing payload locations/occurrences must not reorder semantic content; cover with a
  dedicated fixture (duplicate indexes, multi-page references).
- `WithoutOverlapping` requires a shared cache store; production uses the database cache — note
  that tests run on `array` cache, where the lock is per-process (fine for tests).
