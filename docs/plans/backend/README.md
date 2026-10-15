# Backend Implementation Plan — Dafpus Cek

> **Status:** broad plan (v1) — written before implementation of the remaining backend slices.
> Detailed, task-level plans are intentionally **not** included here. Each phase document is
> structured so it can be expanded into one or more detailed plans without re-deriving any
> contract, invariant or acceptance criterion.

This folder plans the completion of the **Laravel backend only**. The frontend wiring, the
FastAPI inference service implementation and deployment are separate efforts (see §4).

---

## 1. How to use this plan

1. Read this file first — it defines scope, cross-cutting rules, phase order and open questions.
2. Read the phase document you are about to plan/implement. Each phase has the same shape:
   objective, scope, deliverables, design guidance, contracts/invariants, tests/acceptance,
   open decisions, risks.
3. Before starting a phase, read the decisions it references (§11 and the `Decision log`). All
   initial questions are resolved; if a phase uncovers a new ambiguity, record it and its
   resolution in the same place.
4. When expanding a phase into a **detailed plan**, use this task template per deliverable:

```text
[ ] Resolve/confirm relevant OQ decisions
[ ] Migration / model / relationship / factory (if data is touched)
[ ] FormRequest validation (filters, enums, ownership inputs)
[ ] Service / job / client (domain logic, transactions, idempotency)
[ ] DTO(s) per naming convention (Summary / Detail / Preview)
[ ] Controller + route + rate limit middleware
[ ] Tests: happy path, validation 422, ownership 404, failure modes, envelope assertions
[ ] vendor/bin/pint --dirty && php artisan test
[ ] Update docs/ARCHITECTURE.md §13 and AGENTS.md §14 status if the slice changes reality
```

5. This plan is a living document. When a decision is made, update the relevant phase document
   (and this README if it changes ordering/scope) instead of leaving it ambiguous.

### Decision log

| ID | Question | Decision | Date | Decided by |
|---|---|---|---|---|
| OQ-01 | Upload-time `503` policy | Always `202` after persistence; no preflight by default (`analysis.require_inference_at_upload=false` opt-in). Unavailability surfaces as `failed` + `analysis_error`. | 2026-10-06 | user (default) |
| OQ-02 | Non-resolving DOI classification | `invalid` with `"DOI tidak ditemukan di Crossref."`; `not_found` only when no DOI and no candidate. | 2026-10-06 | user (default) |
| OQ-03 | Crossref outage policy | Total outage → document `failed` with a safe error; per-reference transient failures → `pending` with an explicit reason. | 2026-10-06 | user (default) |
| OQ-04 | SBERT unavailable | Degrade to string signals, record the degradation in `reason`, document still completes; extract failure stays fatal. | 2026-10-06 | user (default) |
| OQ-05 | PDF generation | **Gotenberg** (user-selected): render Blade HTML, POST to `/forms/chromium/convert/html`. Modern CSS support, no Dompdf quirks. Behind a `ReportRenderer` interface; internal service on the private network. | 2026-10-06 | user |
| OQ-06 | Report file link | `file_id` is canonical per `API_SPEC.md` §11; keep the polymorphic `files` row; add nullable FK `file_id → files.id` (`nullOnDelete`) and update `DB_SCHEMA.md`. | 2026-10-06 | user (default) |
| OQ-07 | Report multiplicity | Allow multiple report rows (history); document-scoped content per report. | 2026-10-06 | user (default) |
| OQ-08 | `generating_report` step | Bookkeeping/finalization only; no auto-generated report. | 2026-10-06 | user (default) |
| OQ-09 | Findings feed contents/order | Include `reference_pending` (severity `info`); order by `text_start_offset` ASC then id; add `reference_pending` to the `type` filter (spec note). | 2026-10-06 | user (default) |
| OQ-10 | `summary` before completion | `summary: null` until `completed`. | 2026-10-06 | user (default) |
| OQ-11 | `CROSSREF_API_KEY` | Dropped for direct access; use `CROSSREF_MAILTO` + contact User-Agent. | 2026-10-06 | user (default) |
| OQ-12 | Scoring weights/thresholds | Provisional defaults in `config/scoring.php`, tuned via the Phase 07 harness; record the config per run. | 2026-10-06 | user (default) |
| OQ-13 | Inference service scope | Separate plan; backend uses contract fakes until it exists. | 2026-10-06 | user (default) |
| OQ-14 | Finding uniqueness | Add unique index on `reference_findings.researched_document_reference_id` + update `DB_SCHEMA.md`. | 2026-10-06 | user (default) |
| OQ-15 | Morph map | Enforce morph map (`researched_document`, `generated_document_report`) for `files.fileable_type`. | 2026-10-06 | user (default) |
| OQ-16 | Report content | Indonesian; document info, summary counts, reference table, citation issues; isolated Blade template. | 2026-10-06 | user (default) |
| OQ-17 | Manual review state | Create a manual finding when the reference exists and the document is not `processing`; `409` while processing (never overwrite a running pipeline). | 2026-10-06 | user (default) |
| OQ-18 | IEEE ordinal basis | Bibliography order from `text_start_offset` ASC (nulls last, id tiebreak); record the assumption. | 2026-10-06 | user (default) |

---

## 2. Source of truth

When anything conflicts, the order in `AGENTS.md` §2 applies:

1. `docs/API_SPEC.md` — canonical public API contract (English version wins over
   `docs/API_SPEC_ID.md`).
2. `docs/DB_SCHEMA.md` — canonical schema; **first** DBML block only (the second block is a stale
   draft).
3. `docs/Proposal Capstone Project Kelompok 7.pdf` (+ `.md` rendering) — product intent.
4. Existing implementation — authoritative for current behaviour, never a reason to redefine a
   documented contract.

Supporting docs (`docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TEST_PLAN.md`,
`docs/PRODUCT_REQUIREMENTS.md`) explain the specs but never override them. Any change to an
endpoint shape, enum, status or table must update `docs/API_SPEC.md` / `docs/DB_SCHEMA.md` in the
same change.

---

## 3. Current backend status (verified against the repo)

### 3.1 What exists

- Laravel 13 app (`laravel/framework ^13.17`, PHP `^8.3`), Pint/Pest 5, `spatie/laravel-data` 4.
- Canonical domain migration `2026_09_26_135737_create_initial_tables.php` (all 9 domain tables,
  UUID PKs, FK cascades, unique indexes).
- Models: all nine domain models (`User`, `ResearchedDocument`, `ResearchedDocumentReference`
  `+ Location`, `ResearchedDocumentCitation` `+ Location`, `ReferenceFinding` `+ Candidate`,
  `GeneratedDocumentReport`, `File`) with factories and states.
- Enums: `app/Enums/` (`DocumentStatus`, `AnalysisStep`, `ReferenceFindingStatus`, `CitationStatus`,
  `ReportStatus`, `FindingType`, `FindingSeverity`) plus pinned-value unit tests.
- Foundation primitives (Phase 01): `ApiResponse`/`ApiError`, `ApiException` + framework renderers,
  per-resource 404/409/503 exceptions, `OwnedResourceFinder` + `ScopesThroughDocument`,
  `documents`/`document-status` rate limiters, `crossref`/`inference`/`scoring` config,
  enforced morph map, `Tests\Support\DocumentTree`/`Fixtures` harness, OQ-14 unique-index migration.
- Sanctum auth: `/api/v1/auth/{register,login,logout,me}` with `AuthController`, `AuthService`,
  FormRequests, `AuthenticationData`/`UserDetailData`, `InvalidCredentialsException`.
- Upload slice: `POST /api/v1/documents` → `UploadController`, `UploadDocumentRequest`,
  `DocumentUploadService` (transactional document + file persistence via `DocumentFileManager`),
  `ResearchedDocumentDetailData` + `FilePreviewData`; upload dispatches `AnalyzeDocumentJob`
  after commit through the `RunsDocumentAnalysis` seam (`config/analysis.php`).
- Document lifecycle (Phase 02): `DocumentController` + `ListDocumentsRequest` implementing
  `GET /documents`, `GET /documents/{document}`, `GET /documents/{document}/status`,
  `POST /documents/{document}/retry`, `DELETE /documents/{document}`, `DELETE /documents`;
  `DocumentQueryService`, `DocumentSummaryService` (batched aggregate counts),
  `DocumentLifecycleService`, `DocumentAnalysisStateService`, `DocumentAnalysisResetService`,
  `DocumentDeletionService`, `DocumentFileManager`, `CitationStatusResolver`;
  `ResearchedDocumentSummaryData`/`ResearchedDocumentStatusData`/`DocumentAnalysisSummaryData`.
- Jobs: `AnalyzeDocumentJob` (`ShouldQueue`, status guard, `WithoutOverlapping`, `failed()`
  safety net).
- Analysis pipeline (Phase 03): `AnalysisPipeline` bound to `RunsDocumentAnalysis` via
  `AnalysisServiceProvider`; canonical step machine (`AnalysisStepRegistry`, `AnalysisProgress`,
  `AnalysisContext`, `AnalysisFailureHandler`); implemented steps `ExtractDocumentStep`,
  `PersistExtractionStep` (idempotent, schema-normalizing) and `FinalizeAnalysisStep`;
  `config/analysis.php` carries the queue/timeout/lock buffer, the progress map and extraction
  bounds.
- Internal inference client (Phase 03): `Services/Inference/InferenceClient` calling
  `/health`, `/v1/extract`, `/v1/embeddings`; `Data/Inference/*` DTOs; `ExtractionFailedException`,
  `InferenceClientException`; `Services/Crossref/DoiNormalizer`.
- Crossref verification and scoring (Phase 04): `Services/Crossref/*` (`CrossrefClient`,
  `CrossrefQueryBuilder`, `CrossrefResultMapper`, `CrossrefWorkData`, `ReferenceQuery`, `DoiLookup`,
  `CrossrefUnavailableException`); the pure `Services/Scoring/*` engine (`StringSimilarity`,
  `AuthorMatcher`, `SemanticSimilarity`, `LocalVenueDetector`, `MatchReasonBuilder`,
  `ReferenceScorer`, `VerdictDecider`, `ScoringConfig` + value objects); the
  `ValidateReferencesStep` / `EmbedReferencesStep` / `ScoreReferencesStep` pipeline steps and the
  `ReferenceFindingWriter` single writer; `config/scoring.php` `semantic.title_blend` and the
  `services.crossref` retry/back-off/User-Agent keys.
- Citation resolution and review (Phase 05): `Services/Citations/*` (`CitationMarkerParser`,
  `CitationResolver`, `CitationMatchConfig`, `CitationReference`/`CitationResolution` value
  objects) and the `ResolveCitationsStep`; `Services/Reference/ReferenceQueryService`,
  `Services/Citation/{CitationQueryService,CitationPairingService}`, the shared
  `Data/Location/LocationPreviewData` and the reference/citation/finding DTOs; the manual writers
  `ReferenceFindingReviewService` and `CitationPairingService`; the `/references` /
  `/citations` / `/findings` endpoints (`ReferenceController`, `CitationController`,
  `FindingsController`) and the derived feed
  (`Services/Findings/{FindingsFeedQuery,FindingsFeedComposer}`); `config/scoring.php`
  `citation_matching.surname_threshold`.
- Citation resolution robustness (Phase 05.1): `AuthorName` + `AuthorMatcher::names()`,
  `CitationCandidateScorer`, `CitationDecisionPolicy`, `CitationBatchResolver`, `ParsedAuthorYear`,
  the extended `CitationMarkerParser` (all APA pairs/ordinals, initials), `CitationExtractionHints`
  (validated GROBID `reference_index` prior), `CitationResolutionWriter` + `citation_resolution_candidates`
  persistence, `CitationResolutionState`/`CitationResolutionMethod`, the `unresolved` derived status,
  and the `citations:evaluate` harness + seed dataset.
- Report generation (Phase 06): the `files.disk` column + report `file_id` FK migrations;
  `config/reports.php` + `services.gotenberg`; the `ReportRenderer` seam with
  `GotenbergReportRenderer` (official `gotenberg/gotenberg-php ^2.25` client over an injected Guzzle
  PSR-18 client) and `ReportServiceProvider`; `Services/Files/PrivateFileUrlResolver` (delegated to
  by the `UrlFromFilePath` injector); the disk-aware `DocumentFileManager`
  (`storePdf`/`detachForReport`/per-row disk delete); the report read model and template
  (`Services/Reports/{ReportDataBuilder,ReportPayload,ReportReferenceRow,ReportCitationRow,ReportTemplateRenderer}`,
  `resources/views/reports/document-report.blade.php`); `GenerateDocumentReportJob` with
  `ReportStateService`, `ReportFailureHandler` and `ReportDeletionService`; the `/reports` endpoints
  (`ReportController`, `ReportGenerationService`, `ReportQueryService`, `Data/Report/*` DTOs,
  `ListReportsRequest`); report DTO/schema/config tests plus the sync-queue end-to-end suite.
- Canonical error envelope renderers for `401`/`422`/`429` (now centralized in
  `ApiExceptionRenderer`) and `ErrorResponseData`; custom exceptions render their own envelope
  (`DocumentUploadFailedException`, `InvalidCredentialsException`).
- Tests: `AuthTest`, `UploadDocumentTest`, `DomainSchemaTest`, `DomainModelTest`, `EnumTest`,
  `ApiErrorTest`, `ApiResponseTest`, `ApiErrorEnvelopeTest`, `OwnershipIsolationTest`,
  `RateLimitersTest`, `DocumentTreeTest`, `FixturesTest`, plus the Phase 03 suites
  (`InferenceDataTest`, `InferenceClientTest`, `AnalysisProgressTest`, `AnalysisFailureHandlerTest`,
  `ExtractionPersistenceTest`, `AnalysisPipelineTest`, `AnalysisEndToEndTest`, `DoiNormalizerTest`)
  (Pest, SQLite `:memory:`, sync queue).
- Rate limiters `auth` (5/min/IP), `api` (60/min/user), `documents` (10/min/user) and
  `document-status` (120/min/user) in `AppServiceProvider`.
- Storage: default `local` disk rooted at `storage/app/private` with `serve => true`; the
  framework serves it through a **signed** route, which satisfies the private/temporary-URL rule.

### 3.2 What is missing (the work this plan covers)

- Test coverage for the remaining endpoints/pipeline (see `docs/TEST_PLAN.md` §5 and §8).
- The FastAPI inference service implementation (separate effort, OQ-13).

### 3.3 Repo notes that affect the plan

- The local `vendor/` contains `knuckleswtf/scribe` + `deniskorbakov/laravel-data-scribe`, but they
  are **not** in `composer.json`/`composer.lock`. Do not depend on them; adding API-docs tooling is
  a separate, explicit decision.
- `bootstrap/cache/*` is untracked (only `.gitignore` is committed) — treat `php artisan` output
  there as local state.
- Existing tests assert the canonical upload message
  `"Dokumen berhasil diunggah. Analisis sedang diproses."` (Phase 02 changed it from the earlier
  `"Dokumen berhasil diunggah."`).
- `files` is polymorphic with **no FK** on `fileable_id`; deleting a document/report does **not**
  cascade the `files` row. File cleanup must be explicit (Phase 02/06). Every `files` row also
  records the private `disk` it lives on (Phase 06, D-06-02).
- `generated_document_reports.file_id` has a nullable FK to `files.id` with `nullOnDelete`
  (Phase 06, OQ-06), next to the polymorphic `files` relation.

---

## 4. Scope

### 4.1 In scope

- Every endpoint in `docs/API_SPEC.md` §12 that is not yet implemented.
- The asynchronous analysis pipeline orchestrated by Laravel (extraction, Crossref validation,
  scoring, citation resolution, completion/failure, retry).
- Domain models, enums, DTOs, services, jobs, requests, policies/scoping, config, factories.
- Backend tests (Pest) per `docs/TEST_PLAN.md`, including ownership isolation for every endpoint.
- The evaluation harness for scoring quality (backend-side; dataset curation is a separate
  research task).
- Keeping `docs/ARCHITECTURE.md` / `AGENTS.md` status sections current as phases land.

### 4.2 Out of scope

- Frontend (`frontend/`) — including `stores/auth.ts` wiring and `src/types/index.ts` cleanup.
  The plan only preserves the API contract the frontend will consume.
- Implementing the FastAPI inference service (`inference/`). The backend depends on its canonical
  contract (`docs/API_SPEC.md` §9); phases use fakes for tests and require a real service for
  manual end-to-end runs.
- Deployment/CI tooling (none exists in the repo).
- Any feature outside DOI validation and citation consistency (no plagiarism, grammar, etc.).
- DOCX support, OpenAlex, Google Scholar, non-PDF reports — excluded by the canonical contract
  (`AGENTS.md` §15).

### 4.3 External dependencies

| Dependency | Contract | Owner |
|---|---|---|
| Crossref REST API | `docs/API_SPEC.md` §1/§10; client built in Phase 04 | Laravel (outbound HTTP) |
| FastAPI inference (`/health`, `/v1/extract`, `/v1/embeddings`) | `docs/API_SPEC.md` §9 | Separate service; faked in backend tests |
| Gotenberg (HTML → PDF reports) | gotenberg.dev HTTP API (`/forms/chromium/convert/html`), OQ-05 | Internal service, private network; called by report jobs |
| Queue worker (`QUEUE_CONNECTION=database`) | `docs/ARCHITECTURE.md` §8 | Laravel |
| Private file disk (`FILESYSTEM_DISK=local`) | `docs/SECURITY.md` §8 | Laravel |

---

## 5. Target backend shape

Existing conventions are kept; new folders are justified in the last column.

```text
backend/app/
├── Console/Commands/            # NEW: evaluation harness command (Phase 07)
├── Data/
│   ├── Auth/ User/ Error/ File/ ResearchedDocument/      (existing)
│   ├── Reference/ ReferenceFinding/ Citation/ Finding/ Report/   (NEW, per-entity folders)
├── Enums/                       # NEW: canonical enums as backed PHP enums
│   ├── DocumentStatus.php  AnalysisStep.php  ReferenceFindingStatus.php
│   ├── CitationStatus.php  ReportStatus.php  FindingType.php  FindingSeverity.php
├── Exceptions/                  # extend: ResourceNotFound, StateConflict, InferenceUnavailable
├── Extensions/ Helpers/         (existing)
├── Http/
│   ├── Controllers/Api/         # AuthController, UploadController (existing) + new controllers
│   ├── Requests/<Domain>/       # NEW subfolders: Document/ Reference/ Citation/ Finding/ Report/
│   └── Responses/               # NEW: ApiResponse envelope helper
├── Jobs/                        # NEW: AnalyzeDocumentJob, GenerateDocumentReportJob
├── Models/                      # + 6 domain models, Concerns/ for ownership scoping
├── Providers/AppServiceProvider.php
└── Services/                    # domain subfolders
    ├── AuthService.php DocumentUploadService.php          (existing)
    ├── Document/                # list/detail/lifecycle/summary/deletion
    ├── Analysis/                # pipeline steps, progress, reset
    ├── Inference/               # internal FastAPI client + DTOs
    ├── Crossref/                # Crossref client + query/result mapping
    ├── Scoring/                 # string similarity, author matching, semantic similarity, decision
    ├── ReferenceFinding/        # finding + candidate persistence (upsert, ranking)
    ├── Citations/               # marker parsing, resolution, derived status
    ├── Findings/                # derived findings feed
    └── Reports/                 # report data building, Blade view, Gotenberg client, file lifecycle
```

- **Why `app/Enums/`**: `AGENTS.md` §20 forbids silently changing enum values; PHP backed enums
  make the canonical sets (`DocumentStatus`, `AnalysisStep`, `ReferenceFindingStatus`,
  `CitationStatus`, `ReportStatus`, `FindingType`, `FindingSeverity`) single-sourced and usable in
  `Rule::enum()` validation, casts and DTOs.
- **Why `app/Http/Responses/`**: the envelope (`data`/`message`/`meta` and `error`) must be built
  in exactly one place per response kind to avoid drift across ~18 endpoints.
- **Why `app/Jobs/`**: listed in `docs/ARCHITECTURE.md` §5 and required by the async pipeline.

### 5.1 Layering and responsibilities

```text
routes/api.php
   → Controller (transport only: FormRequest → service → ApiResponse)
      → Service / Job (transactions, domain invariants, orchestration)
         → Model (persistence, relationships, scopes, derived accessors)
         → Client (Crossref / Inference HTTP, internal only)
```

Hard rules repeated from `AGENTS.md` §3 / `docs/ARCHITECTURE.md` §2: the frontend never decides
validity; scoring, citation-status derivation and envelope shaping each live in exactly one place.

### 5.2 Naming conventions

- DTOs: `EntitySummaryData` (lists), `EntityDetailData` (detail/action), `EntityPreviewData`
  (embedded). All under `App\Data\<Entity>\`, built with `fromModel()`, `#[MapName(SnakeCaseMapper)]`.
- Services: verb-oriented (`DocumentQueryService`, `ReferenceScorer`), no transport concerns.
- Enums: `lower_snake_case` string values exactly as in the canonical specs.
- Routes: `v1.<resource>.<action>` names, `whereUuid` on all UUID path parameters.

---

## 6. Cross-cutting rules (apply to every phase)

### 6.1 Contract and envelopes

- Success: single `{ data, message? }`; collection `{ data, meta }`; `204` has no body.
- Errors: `{ error: { code, message, details? } }` for every non-2xx, including framework
  exceptions. Canonical code ↔ HTTP mapping in `docs/API_SPEC.md` §2.5.
- User-facing messages: Indonesian where the spec gives an example (`"Dokumen tidak ditemukan."`,
  `"Status referensi diperbarui."`), otherwise follow the spec's example verbatim.
- Never leak stack traces, SQL, paths or internal exception messages (`docs/SECURITY.md` §3).

### 6.2 Enums

- Canonical sets only; new values require a deliberate `docs/API_SPEC.md` update.
- Validate request filters with the enum (`Rule::enum(...)` / `in:` per spec), invalid → `422`.
- Serialize values as `lower_snake_case` strings.

### 6.3 Ownership (release blocker)

- A resource is accessible only through its parent `researched_document.user_id`.
- Foreign access → **`404 NOT_FOUND`**, never `403` and never a distinguishing message.
- Prefer scoped queries over post-hoc checks. Recommended pattern:

```php
// Document:          $user->researchedDocuments()->findOrFail($id)
// Child resource:    Model::query()->forUser($user)->findOrFail($id)   // scope uses whereHas('researchedDocument')
//                    wrapped by an OwnedResourceFinder that throws the per-resource
//                    ResourceNotFoundException so the message matches the spec.
```

Every new endpoint gets at least one ownership-isolation test (two users) per
`docs/TEST_PLAN.md` T-OWN.

### 6.4 Asynchronous processing

- Upload persists and returns; analysis always runs in the queue.
- Progress is exposed via `analysis_progress` / `analysis_step` / `analysis_error`.
- Jobs must be idempotent and safe to retry; reset derived rows before a re-run (Phase 02).
- Do not dispatch a job inside a transaction that has not committed (`afterCommit`).

### 6.5 Derived values (never persisted)

- Citation status: `hallucination` (unpaired) / `pending` / `valid` / `unreliable`, derived from
  the pairing + the reference finding status. One resolver, reused by DTOs, filters and summary.
- Finding severity and the findings feed: derived. No new columns without a canonical schema
  change.
- Document summary counts: derived with aggregate queries, not stored.

### 6.6 Validation

- FormRequests own shape validation; canonical failures map to `422` (plus `413`/`415` for
  upload, already implemented).
- `per_page` default 15 / max 100; invalid filters/sorts → `422`.
- Manual review candidate must belong to the finding; citation pairing must target a reference of
  the same document.
- Treat extracted text/DOIs as untrusted data; parameterised queries only.

### 6.7 Rate limits (per `docs/API_SPEC.md` §2.9)

| Limiter | Route scope | Limit |
|---|---|---|
| `auth` | `POST /auth/login`, `POST /auth/register` | 5/min/IP (exists) |
| `documents` | `POST /documents` | 10/min/user (to add) |
| `document-status` | `GET /documents/{document}/status` | 120/min/user (to add) |
| `api` | all authenticated endpoints | 60/min/user (exists) |

### 6.8 File handling

- Private disk only; download URLs must be signed/temporary (local disk already signs served
  files; keep `FilePreviewData`'s temporary-URL logic).
- Deleting a document/report must remove **both** the `files` row and the stored object, because
  the polymorphic relation has no DB cascade.

### 6.9 Logging and observability

- Log pipeline failures with document id and step; never log passwords, tokens, full document
  text or signed URLs.
- `analysis_error` stores a safe, user-facing message; the raw exception goes to the log only.

### 6.10 Robustness principles (preferred over shortcuts)

1. One shared implementation per business rule (scoring, citation derivation, envelope shaping,
   ownership resolution).
2. Thresholds/weights/timeouts are configuration, not hardcoded numbers.
3. Pipeline steps are idempotent, transactional per step, and isolated per reference where
   possible.
4. External calls have explicit connect/read timeouts, bounded retries and a defined degradation
   policy (never a silent wrong verdict).
5. Every list endpoint validates filters and paginates deterministically (stable sort + tiebreaker).
6. No N+1: eager-load relations used by DTOs; summaries via grouped aggregate queries.
7. Contract changes always update `docs/API_SPEC.md` / `docs/DB_SCHEMA.md` in the same change.

---

## 7. Phase map

| Phase | Document | Objective | Depends on |
|---|---|---|---|
| 01 | [`01-foundation.md`](01-foundation.md) → [`01-foundation-detail.md`](01-foundation-detail.md) | Shared primitives: enums, error envelopes, ownership, response helper, models/factories, config, rate limits, route skeleton, test harness | — |
| 02 | [`02-document-lifecycle.md`](02-document-lifecycle.md) | `/documents` endpoints (list/detail/status/retry/delete/purge), summary counts, upload job dispatch, file cleanup | 01 |
| 03 | [`03-analysis-pipeline.md`](03-analysis-pipeline.md) | `AnalyzeDocumentJob`, inference client, extraction persistence, progress/state machine, failure handling | 02 |
| 04 | [`04-crossref-verification-and-scoring.md`](04-crossref-verification-and-scoring.md) → [`04-crossref-verification-detail.md`](04-crossref-verification-detail.md) | Crossref client, DOI/bibliographic lookup, candidate ranking, scoring, finding upsert | 03 |
| 05 | [`05-citation-resolution-and-review.md`](05-citation-resolution-and-review.md) → [`05-citation-resolution-and-review-detail.md`](05-citation-resolution-and-review-detail.md) | Citation resolution + derived status, references/citations/findings endpoints, manual review | 04 |
| 05.1 | [`05-1-citation-resolution-robustness-detail.md`](05-1-citation-resolution-robustness-detail.md) | Resolution robustness: hints, initials, soft year, ambiguity/`unresolved`, candidates, provenance, evaluation harness | 05 |
| 06 | [`06-reports.md`](06-reports.md) | Async PDF report generation + `/reports` endpoints + file lifecycle | 02 (shell), 05 (content) |
| 07 | [`07-hardening-and-evaluation.md`](07-hardening-and-evaluation.md) | Full test matrix, evaluation harness, performance, docs sync, e2e verification | 01–06 |

```text
01 ──▶ 02 ──▶ 03 ──▶ 04 ──▶ 05 ──▶ 06 ──▶ 07
                 └──────────────▶ (06 may start its endpoint shell after 02;
                                   report content requires 05)
```

Phase 01 is blocking: every later phase reuses its enums, response helper, scoping pattern and
test harness.

---

## 8. Traceability

### 8.1 Endpoint → phase

| Endpoint (`docs/API_SPEC.md` §12) | Spec § | Phase | Status |
|---|---|---|---|
| `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` | 3 | 01 (verify/harden) | implemented |
| `POST /documents` | 4 | 02 | implemented (dispatches `AnalyzeDocumentJob`; pipeline internals Phase 03) |
| `GET /documents` | 4 | 02 | implemented |
| `GET /documents/{document}` | 4 | 02 | implemented |
| `GET /documents/{document}/status` | 4 | 02 | implemented |
| `POST /documents/{document}/retry` | 4 | 02 | implemented |
| `DELETE /documents/{document}` | 4 | 02 | implemented |
| `DELETE /documents` | 4 | 02 | implemented |
| `GET /documents/{document}/references` | 5 | 05 | implemented |
| `GET /references/{reference}` | 5 | 05 | implemented |
| `PATCH /references/{reference}/finding` | 5 | 05 | implemented |
| `GET /documents/{document}/citations` | 6 | 05 | implemented |
| `GET /citations/{citation}` | 6 | 05 | implemented |
| `PATCH /citations/{citation}` | 6 | 05 | implemented |
| `GET /documents/{document}/findings` | 7 | 05 | implemented |
| `POST /documents/{document}/reports` | 8 | 06 | implemented |
| `GET /documents/{document}/reports` | 8 | 06 | implemented |
| `GET /reports/{report}` | 8 | 06 | implemented |
| `DELETE /reports/{report}` | 8 | 06 | implemented |

### 8.2 Requirements → phase

| Requirement group | Source | Phase |
|---|---|---|
| FR-A1..A5 (auth) | `docs/PRODUCT_REQUIREMENTS.md` §6.1 | 01 |
| FR-D1..D7 (documents) | §6.2 | 02 |
| FR-R1..R3, FR-R7 (pipeline verification, suggested DOI) | §6.3 | 03, 04 |
| FR-R4..R6 (reference API + manual review) | §6.3 | 05 |
| FR-C1..C2 (citation pipeline) | §6.4 | 05 |
| FR-C3..C6 (citation API, derivation) | §6.4 | 05 |
| FR-F1..F2 (findings feed) | §6.5 | 05 |
| FR-F3..F5 (reports) | §6.5 | 06 |
| NFR-1..NFR-10 | §7 | all (01 sets the primitives) |

### 8.3 Test IDs → phase

| Test group (`docs/TEST_PLAN.md` §5) | Phase |
|---|---|
| T-AUTH-01..09 | 01 (existing coverage verified; throttling already tested) |
| T-DOC-01..17 | 02 |
| T-OWN-01..04 | 02 |
| T-OWN-05..07 | 05 |
| T-OWN-08 | 06 |
| T-OWN-09 | all phases (assert in each endpoint suite) |
| T-REF-01..06 | 05 |
| T-REF-07..08 | 04 |
| T-CIT-01..09 | 05 |
| T-FIND-01..04 | 05 |
| T-REP-01..05 | 06 |
| T-SCORE-01..07 | 04 |
| T-PIPE-01..05 | 03 (05 for resolution step) |
| T-INF-01..07 | inference service (out of scope); backend uses contract fakes in 03 |
| T-SCHEMA-01..04 | 01 (already green) + 02/06 (file cleanup) |
| T-FE-01..03 | frontend (out of scope) |
| Evaluation harness | 07 |

---

## 9. Definition of done (per phase and per change)

1. Every endpoint in the phase matches `docs/API_SPEC.md` (status code, envelope, field names,
   enum values, messages where specified).
2. Ownership isolation tests exist for every endpoint (foreign access → `404`, body does not
   confirm existence).
3. Happy path + validation + failure modes covered by Pest tests (`docs/TEST_PLAN.md` §9).
4. `php artisan test` passes; `vendor/bin/pint --dirty` reports no changes.
5. No secrets committed; `.env`/`.env.example` only gain placeholders.
6. Any contract discrepancy encountered is reported, not silently fixed. If a contract must
   change, `docs/API_SPEC.md` / `docs/DB_SCHEMA.md` change in the same work.
7. `docs/ARCHITECTURE.md` §13 and `AGENTS.md` §14 status reflect the new reality.

---

## 10. Configuration and environment additions

Config lives in Laravel config files with `.env` overrides; `.env.example` gets placeholders only
(never real values).

| Config key | Env var | Default | Phase | Purpose |
|---|---|---|---|---|
| `services.crossref.base_url` | `CROSSREF_BASE_URL` | `https://api.crossref.org` | 04 | Crossref REST base |
| `services.crossref.mailto` | `CROSSREF_MAILTO` | — | 04 | Polite-pool contact; **required** for responsible use |
| `services.crossref.timeout` | `CROSSREF_TIMEOUT` | `10` | 04 | Read timeout (s) |
| `services.crossref.connect_timeout` | `CROSSREF_CONNECT_TIMEOUT` | `5` | 04 | Connect timeout (s) |
| `services.crossref.rows` | `CROSSREF_ROWS` | `5` | 04 | Bibliographic search page size |
| `services.crossref.cache_ttl` | `CROSSREF_CACHE_TTL` | `86400` | 04 | DOI-lookup cache TTL (s) |
| `services.inference.base_url` | `INFERENCE_BASE_URL` | `http://inference:8000` | 03 | Internal FastAPI base |
| `services.inference.timeout` | `INFERENCE_TIMEOUT` | `120` | 03 | Extract/embed read timeout (s) |
| `services.inference.connect_timeout` | `INFERENCE_CONNECT_TIMEOUT` | `5` | 03 | Connect timeout (s) |
| `services.inference.embedding_batch_size` | `INFERENCE_EMBEDDING_BATCH_SIZE` | `32` | 04 | Batch size for `/v1/embeddings` |
| `services.gotenberg.base_url` | `GOTENBERG_URL` | `http://gotenberg:3000` | 06 | Gotenberg HTTP base (internal service) |
| `services.gotenberg.timeout` | `GOTENBERG_TIMEOUT` | `60` | 06 | PDF render read timeout (s) |
| `services.gotenberg.connect_timeout` | `GOTENBERG_CONNECT_TIMEOUT` | `5` | 06 | Connect timeout (s) |
| `scoring.*` (thresholds, weights, local-venue keywords) | `SCORING_*` (optional overrides) | see Phase 04 | 04 | Tunable matching configuration |
| `analysis.queue` | `ANALYSIS_QUEUE` | `default` | 03 | Queue for `AnalyzeDocumentJob` |
| `reports.disk` | `REPORTS_DISK` | `filesystems.default` | 06 | Private disk for report PDFs; persisted per row in `files.disk` (D-06-02) |
| `reports.queue` | `REPORTS_QUEUE` | `default` | 06 | Queue for `GenerateDocumentReportJob` |
| `reports.timeout` | `REPORTS_TIMEOUT` | `300` | 06 | Job timeout; must stay above `services.gotenberg.timeout` |
| `reports.lock_expiry_buffer` | `REPORTS_LOCK_EXPIRY_BUFFER` | `60` | 06 | Extra seconds the overlap lock outlives the job timeout |
| `reports.template` | `REPORTS_TEMPLATE` | `reports.document-report` | 06 | Blade view rendered to HTML before Gotenberg |
| `reports.text_preview_length` | `REPORTS_TEXT_PREVIEW_LENGTH` | `500` | 06 | Character cap for extracted text in the report |
| `reports.pdf.*` (paper/margins) | `REPORTS_PAPER_*`/`REPORTS_MARGIN_*` | A4/0.4in | 06 | Gotenberg Chromium form fields |

New Composer dependency: `gotenberg/gotenberg-php ^2.25` (MIT; pulls in `php-http/discovery`).

Note: the Crossref REST API itself is unauthenticated. The `CROSSREF_API_KEY` placeholder
mentioned in `AGENTS.md` §17 is not required for direct Crossref access (OQ-11 resolved); use
`CROSSREF_MAILTO` + a contact `User-Agent` instead.

---

## 11. Resolved decisions

All initial questions below were reviewed and decided on **2026-10-06** (see the `Decision log`).
The phase documents reflect these decisions; treat them as the plan's defaults and revisit only
if implementation uncovers new information.

**Doc actions when implemented:** OQ-02/OQ-09 need an `API_SPEC.md` clarification/type-list
update; OQ-06/OQ-14 need `DB_SCHEMA.md` updates; OQ-05 needs a `SECURITY.md` §7 note that
Gotenberg is an internal service like inference.

### Blocking (resolved before the consuming phase)

| ID | Question | Decision | Blocks |
|---|---|---|---|
| OQ-01 | `POST /documents` lists `503 INFERENCE_UNAVAILABLE`, but analysis is asynchronous. When may upload itself return `503`? | Keep upload always `202` after persistence; inference unavailability becomes `status=failed` + safe `analysis_error`, surfaced by status polling. Optionally add an opt-in preflight (`analysis.require_inference_at_upload=false` by default). | 02, 03 |
| OQ-02 | A DOI that is present but does **not** resolve in Crossref: `invalid` or `not_found`? | `invalid` with reason "DOI tidak ditemukan di Crossref" (a broken DOI contradicts the document's own claim); reserve `not_found` for no-DOI/no-candidate. Requires a spec clarification note. | 04 |
| OQ-03 | Crossref is unreachable (connection error/5xx after retries): fail the document or degrade per reference? | If Crossref is entirely unreachable, fail the document with a safe error (no misleading verdicts). Per-reference transient failures inside an otherwise healthy run degrade that reference to `pending` with an explicit reason. | 04 |
| OQ-04 | SBERT `/v1/embeddings` unavailable: fatal or degraded? | Degrade: fall back to string similarity only, record the degraded reason in the finding `reason`; document still completes (aligns with T-SCORE-03). GROBID/`/v1/extract` failure stays fatal. | 03, 04 |
| OQ-05 | Which PDF dependency for report generation? | **Gotenberg** (user-selected): render Blade HTML and POST it to `/forms/chromium/convert/html`. Modern CSS support, no Dompdf quirks. Wrapped in a `ReportRenderer` interface; Gotenberg stays internal/private. | 06 |
| OQ-06 | Report file link: `generated_document_reports.file_id` vs polymorphic `files.fileable`. | `file_id` is the canonical pointer (per `API_SPEC.md` §11) **and** a polymorphic `files` row is kept for uniform handling; add a nullable FK `file_id → files.id` (`nullOnDelete`) in a migration and update `docs/DB_SCHEMA.md`. | 06 |
| OQ-13 | Is the FastAPI inference service implementation part of this plan or separate? | Separate plan; backend defines the client + contract tests with fakes and needs the real service only for manual e2e. | 03 |
| OQ-14 | `reference_findings` has no unique index on `researched_document_reference_id` although one finding per reference is the domain invariant (spec says "upsert"). Add one? | Add a unique index in a deliberate migration + update `docs/DB_SCHEMA.md`; makes concurrency-safe upsert possible. | 03, 04 |
| OQ-15 | `files.fileable_type` stores FQCNs today. Enforce a morph map (`researched_document`, `generated_document_report`)? | Yes, enforce a morph map for stable stored values; only dev/test data exists, so no backfill needed. | 01 |

### Non-blocking (resolved; applied during the phase)

| ID | Question | Decision | Phase |
|---|---|---|---|
| OQ-07 | "One report per document": allow multiple report rows (history) or one active report? | Allow multiple rows (matches the paginated list endpoint and failed-generation retry); "one report per document" is interpreted as document-scoped content. | 06 |
| OQ-08 | The canonical step list includes `generating_report`, but reports are on-demand. What happens during that step? | Do not auto-generate reports; execute `generating_report` as the final bookkeeping step (summary computation + completion) so progress follows the canonical sequence. | 03, 06 |
| OQ-09 | Findings feed: are `reference_pending` items included? What is the sort order? | Include them with severity `info` (severity table implies it) and document position order (`text_start_offset` asc, then id) for viewer stability; this adds `reference_pending` to the `type` filter, which needs a spec note. | 05 |
| OQ-10 | `summary` for a non-`completed` document: omitted or `null`? | Emit `summary: null` until completed for client stability; document the choice. | 02 |
| OQ-11 | `CROSSREF_API_KEY` in `AGENTS.md` §17: add or drop? | Drop for direct Crossref access (no key exists); keep `CROSSREF_MAILTO` + contact User-Agent. If a proxy is used later, add the key deliberately. | 04 |
| OQ-12 | Scoring weights/thresholds are provisional research values (0.85 valid threshold from the proposal). | Ship defaults in `config/scoring.php`, tune via the Phase 07 evaluation harness, and record the config used per evaluation run. | 04, 07 |
| OQ-16 | Report content/sections and language are unspecified. | Indonesian, sections: document info, summary counts, reference table (status/reason), citation issues; isolated Blade template so it can change without touching the job. | 06 |
| OQ-17 | `PATCH /references/{reference}/finding` has no documented rule for a missing finding or a non-`completed` document. | Create a manual finding (confidence `null`) when the reference exists and the document is not `processing`; return `409` while the pipeline is running so a manual verdict is never silently overwritten. | 05 |
| OQ-18 | IEEE ordinals (`[3]`) need a reference order, but the schema has no ordinal column. | Use bibliography order derived from `text_start_offset` ASC (nulls last, `id` tiebreak). Confirm or add a deliberate ordering field to extraction/schema. | 05 |

---

## 12. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Inference service (`/v1/extract`, `/v1/embeddings`) not implemented | Pipeline cannot be verified end-to-end | Contract tests with fakes in CI; manual e2e only when the service exists; OQ-13 decision |
| Scoring thresholds/weights are research-tunable | Findings may look wrong until tuned | Config-driven scoring + evaluation harness (Phase 07); explicit degraded-mode reasons |
| Crossref rate limits / outages | Slow or degraded analysis | Timeouts, bounded retries, DOI cache, polite pool (`mailto`), degradation policy (OQ-03) |
| Gotenberg becomes required for report generation | Reports fail while it is unavailable; ops must run the container | `ReportRenderer` interface, explicit `failed` report status + safe error, private-network deployment, runbook command (Phase 07) |
| Local-venue heuristic ("local" journals → `suspicious`) is fuzzy | False negatives/positives | Configurable keyword list + conservative defaults, tuned against labelled data |
| Polymorphic `files` rows have no DB cascade | Orphaned files/rows after delete | Explicit file lifecycle service + tests (T-DOC-15/16, T-REP-05) |
| SQLite dev vs MySQL/Postgres target | Aggregate/union queries may differ | Keep portable query builder usage; test on SQLite; avoid engine-specific SQL |
| Two stale/lagging spec fragments (`generated_document_reports.file_id`, `generating_report` step) | Ambiguous report design | OQ-06/OQ-08 resolutions before Phase 06 |
