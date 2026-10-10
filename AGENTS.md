# AGENTS.md — Dafpus Cek (repo: Dafpus Sitasi Halu / UI brand: CiteLens)

Operational guide for AI coding agents working in this repository. Read this file before
changing anything, and consult the docs before modifying any contract. Canonical specs:
`docs/API_SPEC.md`, `docs/DB_SCHEMA.md`. Supporting docs: `docs/PRODUCT_REQUIREMENTS.md`,
`docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TEST_PLAN.md` (see §2 and §4).

---

## 1. What This Project Is

**Dafpus Cek** is an academic-reference verification application. A user uploads a document
(PDF), the system extracts its bibliography references and in-text citations, validates each
reference against external publication metadata (Crossref), scores possible matches, resolves
citations to references, and reports problems such as:

- invalid / hallucinated DOI references (a DOI that does not resolve, or resolves to a different
  publication),
- references that look valid but cannot be verified against external metadata,
- valid references without a DOI, where the system suggests a likely DOI,
- mismatches between a bibliography entry and its Crossref metadata,
- in-text citations with no valid bibliography counterpart,
- incorrect author/year combinations in in-text citations,
- citation/reference relationships that need manual review.

The proposal describes three canonical detection cases:

1. A DOI exists in the document but is invalid or points to a different publication.
2. A reference appears valid but has no DOI — the system must search for and suggest a likely DOI.
3. An in-text citation has no valid bibliography counterpart or has an incorrect author/year
   combination.

**This is NOT** a plagiarism detector, a grammar checker, or a general academic-writing quality
evaluator. The proposal explicitly limits scope to DOI validation and citation consistency. Do not
add plagiarism/content-quality features.

Product naming is inconsistent across artifacts: `docs/API_SPEC.md` calls the product **Dafpus
Cek**, the repo is named **Dafpus Sitasi Halu**, and the frontend (`frontend/README.md`) uses
**CiteLens** as UI branding. Keep new user-facing strings consistent with the surrounding code;
do not rename things repo-wide.

## 2. Source-of-Truth Hierarchy

When specifications conflict, use this order:

1. **`docs/API_SPEC.md`** — canonical frontend/backend API contract (public contract).
2. **`docs/DB_SCHEMA.md`** — canonical database/domain schema (use the **first** DBML block; see
   §15).
3. **`docs/Proposal Capstone Project Kelompok 7.pdf`** — product requirements, research scope,
   intended functionality, methodological context.
4. **Existing implementation** — authoritative for how the code currently behaves, but never use
   it to silently redefine a documented contract.

`docs/API_SPEC_ID.md` is an Indonesian translation of `API_SPEC.md`. If the two ever diverge,
`docs/API_SPEC.md` (English) wins.

### Supporting documents (derived — never override the canonical specs)

The following documents restate/expand the specs and the proposal for day-to-day work. They are
**not** independent sources of truth: if a supporting doc conflicts with `API_SPEC.md` or
`DB_SCHEMA.md`, the canonical spec wins and the supporting doc must be corrected.

| Document | Use it for |
|---|---|
| `docs/PRODUCT_REQUIREMENTS.md` | What the product must do: goals, personas, scope, functional/non-functional requirements, acceptance criteria, success metrics. |
| `docs/ARCHITECTURE.md` | How the system is built: components, responsibility boundaries, pipeline, scoring model, state transitions, deployment, current implementation status. |
| `docs/SECURITY.md` | Security invariants: auth, ownership isolation, error non-disclosure, validation, rate limits, secrets, internal-service isolation. |
| `docs/TEST_PLAN.md` | Test strategy, per-feature test matrix, evaluation harness, coverage gaps, exit criteria. |

Before modifying any API shape, enum, status, or table: **read `API_SPEC.md` and `DB_SCHEMA.md`
first** and keep them consistent with your change (or flag that you did not change them).

## 3. Architecture and Responsibility Boundaries

```text
Vue.js SPA
    |
    | REST / JSON   (public contract: docs/API_SPEC.md)
    v
Laravel API
    |
    +-- MySQL/Postgres          (docs/DB_SCHEMA.md; SQLite is the current dev/test default)
    +-- Crossref REST API       (outbound HTTP, orchestrated by Laravel)
    +-- Queue worker            (async analysis pipeline)
    +-- FastAPI inference server (internal, private network only)
             |
             +-- GROBID         (reference + in-text citation + coordinate extraction)
             +-- SBERT          (sentence embeddings / semantic similarity)
```

| Concern | Owner |
|---|---|
| Auth, authorization, persistence, document lifecycle, orchestration, status/progress | Laravel |
| Crossref lookups, candidate ranking, scoring, citation resolution | Laravel |
| String similarity (Jaro-Winkler, Levenshtein), exact matching | Laravel |
| API response formatting, manual review, report-generation orchestration | Laravel |
| Document parsing, reference/citation extraction, coordinates (GROBID) | FastAPI |
| Semantic embeddings (SBERT) | FastAPI |

Hard rules:

- **Laravel is the source of truth.** FastAPI/GROBID/SBERT functionality must never be exposed as
  public API endpoints and must never be called directly by the frontend.
- The FastAPI service (`inference/`) is an **internal inference server only**. It is not part of
  the public contract. Do not add public, frontend-facing FastAPI endpoints unless the API
  contract is explicitly and deliberately changed (including `docs/API_SPEC.md`).
- The frontend must not access the database, call GROBID/SBERT directly, or reimplement backend
  validation/decision logic. The frontend never decides whether a reference is "valid"; it renders
  verdicts computed by Laravel.

## 4. Repository Map

```text
/
├── AGENTS.md                  # this file
├── README.md                  # project stub ("To be implemented")
├── docs/
│   ├── API_SPEC.md            # CANONICAL public API contract (English)
│   ├── API_SPEC_ID.md         # Indonesian translation of API_SPEC.md
│   ├── DB_SCHEMA.md           # CANONICAL DB/domain schema (DBML) + DB_SCHEMA.png
│   ├── PRODUCT_REQUIREMENTS.md # Product requirements (derived; from the proposal)
│   ├── ARCHITECTURE.md        # System architecture, boundaries, pipeline (derived)
│   ├── SECURITY.md            # Security model + invariants (derived)
│   ├── TEST_PLAN.md           # Test strategy, matrix, evaluation harness (derived)
│   ├── designs/               # design artifacts (currently empty)
│   ├── Proposal Capstone Project Kelompok 7.pdf   # source proposal
│   └── Proposal Capstone Project Kelompok 7.md    # Markdown rendering of the proposal
├── frontend/                  # Vue 3 SPA ("CiteLens")
│   ├── package.json           # npm scripts (dev / build / preview / type-check)
│   ├── vite.config.ts         # Vite 6 + Tailwind 4 plugin, alias @/ -> src/
│   ├── components.json        # shadcn-vue config (style "new-york")
│   ├── README.md              # frontend setup guide (Indonesian)
│   └── src/
│       ├── main.ts            # app entry point
│       ├── router/index.ts    # routes + auth guard
│       ├── stores/auth.ts     # Pinia auth store (CURRENTLY MOCK — no API calls yet)
│       ├── types/index.ts     # shared TS types (CURRENTLY mock/prototype types)
│       ├── components/        # common/, layout/, ui/ (shadcn-vue)
│       ├── lib/utils.ts       # cn() helper
│       └── views/             # LoginView, RegisterView, UploadView, ProcessingView,
│                              # ResultView, HistoryView
├── backend/                   # Laravel application
│   ├── artisan
│   ├── composer.json          # PHP ^8.3, laravel/framework ^13.17, Pest 5, Pint
│   ├── phpunit.xml            # Pest/PHPUnit config (sqlite :memory:, sync queue)
│   ├── .env.example
│   ├── AGENTS.md / CLAUDE.md  # Laravel Boost guidelines (auto-generated; follow them)
│   ├── bootstrap/app.php      # middleware/exception bootstrap
│   ├── routes/                # web.php, api.php (API under /api/v1), console.php
│   ├── app/                   # Http/{Controllers/Api,Requests}, Data (spatie/laravel-data DTOs),
│   │                          # Models, Services, Exceptions, Providers
│   ├── config/                # app, auth, database, queue, services, ...
│   ├── database/              # migrations (incl. canonical domain schema), factories, seeders
│   └── tests/                 # Pest tests (tests/Feature, tests/Unit)
└── inference/                 # FastAPI internal inference service (mostly a stub today)
    ├── main.py                # FastAPI app (currently a hello-world stub)
    ├── pyproject.toml         # uv-managed, requires Python >= 3.14, fastapi[standard]
    ├── uv.lock
    └── src/inference/__init__.py
```

Notes:

- There is **no Docker/docker-compose, no CI configuration, no `.github/`, and no ESLint/Prettier
  config** in this repo at the time of writing. Do not cite commands from tooling that does not
  exist; if you add such tooling, do it deliberately and document it here.
- `backend/.env` exists locally and is git-ignored. Never read secrets out of it into code or
  docs, never commit it.

### Important entry points and locations

| What | Where |
|---|---|
| Laravel HTTP bootstrap | `backend/bootstrap/app.php` |
| Laravel routes | `backend/routes/api.php` (API under `/api/v1`), `backend/routes/web.php` |
| Laravel models | `backend/app/Models/` (all nine domain models + `User`) |
| Laravel DTOs | `backend/app/Data/` (spatie/laravel-data; e.g. `ResearchedDocument/ResearchedDocumentDetailData`, `ResearchedDocument/ResearchedDocumentSummaryData`, `File/FilePreviewData`) |
| Laravel controllers | `backend/app/Http/Controllers/Api/` (`AuthController`, `UploadController`, `DocumentController`) |
| Migrations | `backend/database/migrations/` (framework tables + `create_initial_tables` domain schema) |
| Backend tests | `backend/tests/Feature/`, `backend/tests/Unit/` (Pest) |
| Frontend API/auth state | `frontend/src/stores/auth.ts` (mock) |
| Frontend routes/guards | `frontend/src/router/index.ts` |
| Frontend shared types | `frontend/src/types/index.ts` |
| Inference service entry | `inference/main.py` |
| Canonical specs | `docs/API_SPEC.md`, `docs/DB_SCHEMA.md` |
| Supporting docs | `docs/PRODUCT_REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TEST_PLAN.md` |
| Source proposal | `docs/Proposal Capstone Project Kelompok 7.pdf` (+ `.md` rendering) |

## 5. Database / Domain Model

Canonical schema: `docs/DB_SCHEMA.md` (DBML + ER diagram `docs/DB_SCHEMA.png`). All primary keys
are **UUID v4**. Core relationship tree:

```text
users
  |
  +-- researched_documents                (upload + analysis lifecycle)
         |
         +-- files                        (polymorphic: fileable_type / fileable_id)
         |
         +-- researched_document_references      (bibliography entries)
         |      |
         |      +-- researched_document_reference_locations   (page + PDF coords)
         |      |
         |      +-- reference_findings                         (verification verdict)
         |             |
         |             +-- reference_finding_candidates        (ranked Crossref matches)
         |
         +-- researched_document_citations       (one row per in-text occurrence)
         |      |
         |      +-- researched_document_citation_locations     (page + PDF coords)
         |
         +-- generated_document_reports          (async PDF reports)
```

Key concepts:

- **`researched_documents`** — one uploaded document and its analysis lifecycle: `user_id`,
  `name`, `status`, `analysis_progress` (0–100), `analysis_step`, `analysis_error`,
  `analysis_started_at`, `analysis_completed_at`.
- **`researched_document_references`** — bibliography entries: `raw_text`, `doi`, `title`,
  `authors`, `publication_name`, `publication_year`, `text_start_offset`/`text_end_offset`.
- **`researched_document_citations`** — one row per in-text occurrence. One reference can have
  many citation rows. `researched_document_reference_id` is nullable because a citation may be
  unresolved (→ `hallucination`).
- **`*_locations`** — page number (1-based) + bounding box (`x`, `y`, `width`, `height`,
  `page_width`, `page_height`, `location_index`). The coordinate system is
  **`pdf_points_top_left`**. These values feed the document viewer/highlighting UI — do not
  casually change coordinate semantics or origins.
- **`reference_findings`** — the verification verdict per reference: `status`, `confidence`,
  `reason`, `selected_candidate_id`, `is_manual`, `reviewed_by`, `reviewed_at`.
- **`reference_finding_candidates`** — ranked possible external matches: `rank`, `confidence`,
  `doi`, `title`, `authors`, `publication_name`, `publication_year`, `url`, `match_reason`.
- **`generated_document_reports`** — one report per document (PDF), with `status`, nullable
  `file_id`, `error`, `generated_at`.
- **`files`** — polymorphic file records attached to documents and reports.

Deletion is **hard delete with cascade** (references, citations, locations, findings, candidates,
files, reports). Do not replace this with soft deletion unless the API/schema contracts are
deliberately changed.

## 6. API Contract Summary (canonical: `docs/API_SPEC.md`)

Do not invent alternative envelopes or endpoints. Full request/response examples live in the spec.

- **Base path:** `/api/v1` (local: `http://localhost:8000/api/v1`). Send `Accept: application/json`.
- **Auth:** Laravel Sanctum bearer tokens — `Authorization: Bearer <token>`. Only
  `POST /auth/register` and `POST /auth/login` are public; everything else requires auth.
- **IDs:** UUID v4 strings. **Timestamps:** ISO 8601 UTC. **Confidence:** `0.0000`–`1.0000`
  (frontend converts to a percentage). **Enums:** `lower_snake_case`.
- **Success envelopes:**
  - single: `{ "data": {...}, "message": "..." }` (`message` only on actions),
  - collection: `{ "data": [...], "meta": { "current_page", "per_page", "total", "last_page" } }`.
- **Error envelope (all non-2xx):**
  `{ "error": { "code": "...", "message": "...", "details": {...} } }` (`details` optional).

Canonical HTTP / error-code mapping:

| HTTP | code | When |
|---|---|---|
| 400 | `BAD_REQUEST` | malformed request |
| 401 | `UNAUTHENTICATED` | missing/expired/invalid token |
| 403 | `FORBIDDEN` | action not permitted |
| 404 | `NOT_FOUND` | unknown id **or not owned by caller** |
| 409 | `CONFLICT` | invalid state transition (e.g. retry while not failed) |
| 413 | `PAYLOAD_TOO_LARGE` | file exceeds 20 MB |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | file is not a PDF |
| 422 | `VALIDATION_ERROR` | field validation failed |
| 429 | `RATE_LIMITED` | rate limit exceeded |
| 500 | `SERVER_ERROR` | unhandled error |
| 503 | `INFERENCE_UNAVAILABLE` | GROBID/SBERT unreachable or analysis failed |

Endpoint groups (see spec §12 for the full table): `/auth/*`, `/documents` (upload+analyze,
list, detail, status polling, retry, delete one, purge), `/references` (list per document,
detail with candidates/locations/citations, `PATCH /references/{reference}/finding` manual
review), `/citations` (list per document, detail, `PATCH /citations/{citation}` pair/unpair),
`/documents/{document}/findings` (derived read-only findings/highlights feed), `/reports`
(generate/list/detail/delete — PDF only).

Pagination: `page`, `per_page` (default 15, max 100). Invalid filters → `422`. Default sort:
`createdAt` descending unless documented otherwise.

## 7. Authorization and Ownership — MANDATORY INVARIANTS

- A resource is accessible **only** to the owner of its parent `researched_document`. When a user
  requests another user's resource, return **`404 NOT_FOUND`** — never `403`, never confirm that
  the resource exists. This applies to documents, references, citations, findings, and reports.
- Every query touching document-owned data must be scoped through the authenticated user.
- Tokens are issued on register/login and revoked on logout.
- Hard delete cascades (see §5). Do not leak existence in any error message.

Treat these as security invariants: a bug here is a data leak, not a cosmetic issue.

## 8. Asynchronous Document-Analysis Pipeline

Upload and analysis start are **intentionally combined** in `POST /api/v1/documents`. That
endpoint must:

1. store the uploaded file and create `researched_documents` (status `pending`) + the `files` row,
2. dispatch the analysis job (`AnalyzeDocumentJob` in the spec) to the queue,
3. return immediately with `202 Accepted`,
4. run analysis asynchronously.

Clients poll `GET /documents/{id}/status` (recommended: every 2 s, backing off to 5 s after 30 s;
stop on `completed`/`failed`). **Do not introduce synchronous processing into the upload request**
for convenience.

Analysis steps (`researched_documents.analysis_step`):

```text
queued → extracting → persisting → crossref_validation → embedding → scoring
       → resolving_citations → generating_report → completed
```

Pipeline behavior (spec §10):

1. store PDF → create document (`pending`, step `queued`),
2. FastAPI `POST /v1/extract` (GROBID) → persist references, reference locations, citations,
   citation locations,
3. per reference: Crossref lookup (DOI or bibliographic search) → persist ranked
   `reference_finding_candidates`,
4. similarity scoring in **Laravel** (Jaro-Winkler + Levenshtein + SBERT embeddings) → compute
   confidence, pick `selected_candidate_id`, upsert `reference_findings`,
5. resolve citations → set `researched_document_citations.researched_document_reference_id`;
   unpaired citations surface as `hallucination`,
6. compute summary counts → `completed`; on any unrecoverable error → `failed` + `analysis_error`.

Document lifecycle (`researched_documents.status`):

```text
pending → processing → completed
                    ↘ failed
```

- `completed` means the pipeline finished **even if findings exist** — findings are not failures.
- `failed` means the pipeline aborted; `analysis_error` explains why.
- **Retry is only allowed for `failed` documents** (`POST /documents/{document}/retry`); any other
  state returns `409 CONFLICT`. Progress is exposed via `analysis_progress` / `analysis_step` /
  `analysis_error`.

## 9. Supported Input Constraints (API v1)

- **PDF only.** DOCX is **not** supported by the v1 upload API even though the proposal mentions
  PDF + DOCX as intended scope (see §15).
- Maximum upload size: **20 MB** (→ `413 PAYLOAD_TOO_LARGE` above the limit; non-PDF →
  `415 UNSUPPORTED_MEDIA_TYPE`).
- Scanned PDFs without a usable text layer are outside supported processing scope.
- Report generation is **PDF only** in v1 (no DOCX/Excel export).
- Return the documented validation/error responses; do not silently coerce unsupported input.

## 10. Finding and Citation Semantics

Reference finding status (`reference_findings.status`):

```text
pending | valid | suspicious | invalid | not_found
```

| Value | Meaning |
|---|---|
| `pending` | not yet evaluated |
| `valid` | matched confidently |
| `suspicious` | matched but low confidence / partial mismatch |
| `invalid` | match found but metadata conflicts |
| `not_found` | no candidate found in Crossref |

**Citation status is DERIVED, never stored.** Do not add a persisted citation-status column unless
the canonical schema is deliberately changed. Derived values:

| Citation status | Condition |
|---|---|
| `valid` | paired, and reference finding is `valid` or `suspicious` |
| `unreliable` | paired, but reference finding is `invalid` or `not_found` |
| `pending` | paired, but reference finding is still `pending` |
| `hallucination` | unpaired (`researched_document_reference_id` is null) |

Consequence: changing a reference finding (e.g. via manual review) immediately changes the derived
status of every citation pointing to that reference. Compute the derived status in one shared place
(e.g. an Eloquent accessor / API Resource), not duplicated per endpoint.

Finding severity (derived): `high | medium | low | info`, mapped in `API_SPEC.md` §2.6:

- reference `not_found` → high; reference `invalid` → high; citation `hallucination` → high;
- citation `unreliable` → severity of its paired reference finding;
- reference `suspicious` → medium; reference `pending` → info.

Manual review (`PATCH /references/{reference}/finding`) sets `is_manual = true` and stamps
`reviewed_by` / `reviewed_at`; `selected_candidate_id` must belong to that finding. Manual pairing
of citations (`PATCH /citations/{citation}`) may only reference a reference from the **same
document**.

## 11. External Services

- **Crossref** — external, independent source of truth for publication metadata; used for
  reference validation and candidate lookup. **Laravel orchestrates all Crossref requests.** Never
  call Crossref from the frontend, and never expose Crossref credentials to the browser.
- **GROBID** — internal; extracts bibliography entries, in-text citations, and coordinates. Not
  exposed to the frontend.
- **SBERT** — internal; semantic embeddings and title/semantic similarity. Not a public endpoint.
  Batch embedding requests (e.g. 32 texts) to bound latency/memory.
- **Google Scholar** — explicitly **excluded** by the proposal (no official API; scraping
  prohibited by ToS). **Do not add Google Scholar scraping.**

The proposal also names **OpenAlex API** as an allowed external metadata source alongside
Crossref; `API_SPEC.md` only specifies Crossref. See §15 — do not silently add OpenAlex.

## 12. Matching and Verification Logic

Intended combination: exact matching + Levenshtein distance + Jaro-Winkler + SBERT semantic
similarity + Crossref metadata + author/year matching. Fuzzy matching is used for author names;
SBERT for semantic title similarity. Preserve the responsibility split:

```text
GROBID                     → extraction (references, citations, coordinates)
Crossref                   → external metadata candidates
Levenshtein / Jaro-Winkler → string similarity          (implemented in Laravel)
SBERT                      → semantic similarity        (embeddings from FastAPI)
Laravel                    → orchestration, ranking, scoring, decision/finding persistence
```

Do not move the matching algorithm into the frontend, and do not let the frontend independently
decide whether a reference is valid.

## 13. Rate Limits

Implement/preserve these limits from `API_SPEC.md` §2.9:

| Scope | Limit |
|---|---|
| `POST /auth/login`, `POST /auth/register` | 5 / minute / IP |
| `POST /documents` | 10 / minute / user |
| `GET /documents/{id}/status` | 120 / minute / user |
| Authenticated endpoints (general) | 60 / minute / user |

Over-limit → `429 RATE_LIMITED` with the standard error envelope. Do not remove throttling to make
local development easier — relax limits via environment/configuration instead of changing the
documented production contract.

## 14. Current Implementation Status (verified in the repo)

Be aware of what is scaffold vs. contract before promising behavior:

- **`backend/`** is a Laravel 13 app (`laravel/framework ^13.17`, PHP `^8.3`; local CLI is
  PHP 8.5). It has the framework `User` model plus the domain models `ResearchedDocument`,
  `ResearchedDocumentReference` (`+ Location`), `ResearchedDocumentCitation` (`+ Location`),
  `ReferenceFinding` (`+ Candidate`), `GeneratedDocumentReport` and `File`, with factories; the
  canonical enums in `app/Enums/`; the canonical domain migration
  (`database/migrations/*_create_initial_tables.php`) plus the OQ-14 unique-index migration; the
  shared response/error-envelope layer (`app/Http/Responses/{ApiResponse,ApiError}`,
  `app/Exceptions/{ApiException,ApiExceptionRenderer}` + the per-resource 404/409/503
  exceptions); ownership scoping via `app/Services/Ownership/OwnedResourceFinder` + the
  `ScopesThroughDocument` trait; the `documents`/`document-status` rate limiters; the
  `crossref`/`inference`/`scoring` config blocks plus `config/analysis.php`; the enforced morph map
  (`user`, `researched_document`, `generated_document_report`); the **full `/documents` lifecycle**
  in `routes/api.php` (`DocumentController` + `ListDocumentsRequest`; list/detail/status/retry/
  delete/purge; `DocumentQueryService`, `DocumentSummaryService`, `DocumentLifecycleService`,
  `DocumentAnalysisStateService`, `DocumentAnalysisResetService`, `DocumentDeletionService`,
  `DocumentFileManager`, `CitationStatusResolver`); and the upload slice
  (`Http/Controllers/Api/UploadController`, `Http/Requests/UploadDocumentRequest`,
  `Data/ResearchedDocument/*`, `Services/DocumentUploadService`,
  `Exceptions/DocumentUploadFailedException`) which dispatches `AnalyzeDocumentJob` after commit
  via the `RunsDocumentAnalysis` seam. Phase 03 binds that seam to `AnalysisPipeline`: the canonical
  step machine (`AnalysisStepRegistry`, `AnalysisProgress`, `AnalysisFailureHandler`,
  `AnalysisContext`), the implemented steps (`ExtractDocumentStep`, `PersistExtractionStep`,
  `FinalizeAnalysisStep`), the internal inference client (`Services/Inference/InferenceClient` +
  `Data/Inference/*` DTOs + `ExtractionFailedException`), `Services/Crossref/DoiNormalizer`, the
  `WithoutOverlapping` job middleware and the inference fixtures/fakes (`tests/Support/InferenceFake`,
  `AnalysisHarness`). Phase 04 adds the Crossref layer (`Services/Crossref/CrossrefClient` + query
  builder/result mapper/value objects + `Exceptions/CrossrefUnavailableException`), the pure
  scoring engine (`Services/Scoring/*`: string/author/semantic similarity, local-venue detection,
  reason building, `ReferenceScorer`, `VerdictDecider`), the
  `crossref_validation`/`embedding`/`scoring` steps (`ValidateReferencesStep`,
  `EmbedReferencesStep`, `ScoreReferencesStep`), the transient
  `ReferenceVerificationBatch`/`EmbeddingIndex` context artifacts and the single automated writer
  (`Services/ReferenceFinding/ReferenceFindingWriter`). Phase 05 adds citation resolution
  (`Services/Citations/*`: `CitationMarkerParser`, `CitationResolver`, `CitationMatchConfig`,
  `CitationReference`/`CitationResolution`, `ResolveCitationsStep`), the verification read model
  (`Services/Reference/ReferenceQueryService`, `Services/Citation/CitationQueryService`, the
  reference/citation/finding DTOs and a shared `Data/Location/LocationPreviewData`), the manual
  writers (`Services/ReferenceFinding/ReferenceFindingReviewService`,
  `Services/Citation/CitationPairingService`) and the `/references` / `/citations` / `/findings`
  endpoints (`ReferenceController`, `CitationController`, `FindingsController`; derived feed in
  `Services/Findings/{FindingsFeedQuery,FindingsFeedComposer}`). Phase 05.1 hardens citation
  resolution: `CitationMarkerParser` retains every APA pair/ordinal, `AuthorMatcher::names()` adds
  initials, `CitationCandidateScorer`/`CitationDecisionPolicy`/`CitationBatchResolver` implement
  soft-year scoring, ambiguity detection and cross-citation evidence consolidation, the GROBID
  `reference_index` is consumed as a validated prior, resolution provenance
  (`resolution_state`/`resolution_method`/`resolution_confidence`/`extraction_reference_index`) and
  `citation_resolution_candidates` are persisted, `unresolved` is a fifth derived status (severity
  `medium`), and `citations:evaluate` provides a seed evaluation harness. API responses are shaped with
  **`spatie/laravel-data`** DTOs in `app/Data/` (the former `Http/Resources` layer was replaced).
  **Sanctum is installed** and the `/auth/*` endpoints (register, login, logout, me) are
  implemented (`Http/Controllers/Api/AuthController`, `Services/AuthService`, `Http/Requests/Auth/*`,
  `Data/Auth/AuthenticationData` + `Data/User/UserDetailData`, `Data/Error/ErrorResponseData`,
  `Exceptions/InvalidCredentialsException`); report generation (Phase 06) and the FastAPI inference
  implementation do not exist yet. Adding them is expected work — follow the contracts while doing
  it. See `docs/ARCHITECTURE.md` §13 for the full status.
- **`frontend/`** is a working Vue 3 + Vite 6 + TypeScript + Tailwind 4 + Pinia + vue-router SPA
  with shadcn-vue (`reka-ui`) components. **`src/stores/auth.ts` is mocked** (setTimeout, no API
  calls) and `src/types/index.ts` uses prototype types (`valid | warning | halu`, numeric ids,
  `trustScore`, etc.) that do **not** match `API_SPEC.md`. When wiring the real API, map to the
  canonical enums/UUIDs — treat current types as disposable UI scaffolding, not as contract.
- **`inference/`** is a FastAPI hello-world stub (`main.py`) managed by **uv** (Python 3.14,
  `fastapi[standard]`). The internal API (`/health`, `POST /v1/extract`, `POST /v1/embeddings`)
  from `API_SPEC.md` §9 is specified but not implemented yet.
- Backend `AGENTS.md` / `CLAUDE.md` contain the Laravel Boost guidelines (PHP style, Pint, Pest,
  Artisan usage). Follow them when working inside `backend/`.

## 15. Known Specification Discrepancies

Do not "fix" these silently. Identify the conflict, prefer the canonical contract for
implementation, and mention it in your summary when it affects the task.

1. **DOCX:** the proposal scopes PDF + DOCX; `API_SPEC.md` v1 is **PDF only**. Do not implement
   DOCX in v1 unless the API contract is explicitly changed (and `API_SPEC.md` updated).
2. **OpenAlex:** the proposal allows Crossref **and** OpenAlex as validation sources;
   `API_SPEC.md` defines Crossref only. Do not silently add OpenAlex; if needed, change the
   contract deliberately.
3. **`docs/DB_SCHEMA.md` contains two DBML blocks.** The second one (after the stray "lmao"
   heading) is a **stale older draft** (no analysis-progress columns, no location tables for
   references, no manual-review fields). The **first block is canonical**.
4. **Citation styles:** the proposal limits format validation to **APA and IEEE** styles — relevant
   if you touch citation parsing/normalization.
5. **Frontend prototype types** (`valid | warning | halu`) vs API enums — see §14; the API wins.
6. **Database engine:** `API_SPEC.md`/`DB_SCHEMA.md` target MySQL/Postgres, but the current dev
   setup (`.env.example`, `phpunit.xml`) uses SQLite. Keep migrations portable; do not change the
   documented production target silently.
7. **Naming:** Dafpus Cek / Dafpus Sitasi Halu / CiteLens refer to the same product (§1).
8. **API_SPEC.md vs API_SPEC_ID.md:** the English document is canonical; the Indonesian file is a
   translation.

Handling procedure: (1) identify the conflict; (2) determine whether it affects the requested
change; (3) implement against the canonical API/database contract; (4) never silently change the
contract; (5) note the discrepancy in your final summary when relevant; (6) ask for clarification
only when the conflict makes a safe implementation impossible.

## 16. Commands and Tooling (verified — do not invent others)

### Backend (`backend/`)

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate   # first-time setup
php artisan migrate
composer run dev            # Laravel dev server (artisan dev)
```

- Tests (Pest 5): `php artisan test`, `composer test`, or `vendor/bin/pest` — narrow scope with
  `vendor/bin/pest tests/Feature/FooTest.php` or `php artisan test --compact --filter=name`.
  `phpunit.xml` runs tests on in-memory SQLite with a **sync** queue.
- Formatter: **Laravel Pint** — run `vendor/bin/pint --dirty` after editing PHP.
- Create scaffolding with Artisan: `php artisan make:model`, `make:controller`, `make:job`,
  `make:migration`, `make:test --pest`. Most tests should be feature tests.
- No static-analysis tool (PHPStan/Larastan) is configured.

### Frontend (`frontend/`)

```bash
cd frontend
npm install
npm run dev          # Vite dev server on :5173
npm run build        # vue-tsc -b && vite build
npm run type-check   # vue-tsc --noEmit
npm run preview
```

- **No frontend test runner and no ESLint/Prettier config exist.** `npm run type-check` and
  `npm run build` are the only automated checks today. If you add meaningful logic, consider
  introducing a test setup deliberately (and update this file).
- New shadcn-vue UI components: `npx shadcn-vue@latest add <component>` (lands in
  `frontend/src/components/ui/`). Import alias `@/` → `frontend/src/`.

### Inference (`inference/`)

```bash
cd inference
uv sync
uv run uvicorn main:app --reload     # internal service; app lives in inference/main.py
```

- No tests configured yet (add pytest deliberately if you implement real endpoints).

## 17. Environment and Secrets

- Backend env template: `backend/.env.example` (App/DB/session/queue/cache/mail settings;
  `QUEUE_CONNECTION=database` by default). Local `backend/.env` is git-ignored.
- Frontend currently has **no** `.env` / `VITE_*` usage; the API base URL is not wired yet. When
  wiring it, use a `VITE_API_BASE_URL`-style variable (see `frontend/README.md`), not hardcoded
  URLs.
- Inference is uv-managed (`pyproject.toml`, `uv.lock`, `.python-version` = 3.14).
- When external-service configuration is needed (e.g. inference base URL, Crossref access), add it
  through Laravel config (`backend/config/services.php`) + `.env.example` with placeholders such
  as `CROSSREF_API_KEY=<configured through environment>`.
- **Never commit secrets.** Never put real credentials, API keys, tokens, or passwords in code,
  `.env.example`, `AGENTS.md`, tests, or fixtures. `.env` files are git-ignored — keep them that
  way.

## 18. Testing and Validation Expectations

There is very little test coverage today (Pest example tests only). If you modify important
behavior and no test covers it, add one (Pest feature test) when practical. Prioritize:

- authentication (register/login/logout/me, token issuance/revocation),
- **ownership isolation** (cross-user access must 404 — every new endpoint),
- upload validation (PDF-only, 20 MB limit, error envelopes),
- document lifecycle (pending/processing/completed/failed) and progress reporting,
- queue dispatch on upload (`Queue::fake()` / `Bus::fake()` in Pest),
- retry rules (only `failed` → else `409`),
- finding status transitions, especially `PATCH /references/{reference}/finding` (is_manual audit
  fields, candidate validation),
- citation status derivation (all four derived statuses) and citation pairing validation,
- Crossref candidate ranking/scoring behavior (mock external HTTP),
- manual review and report generation (report only for `completed` documents),
- API response envelopes (`data`/`message`/`meta`, `error.code`) and rate-limit responses.

For the inference service, test the internal contract (`/health`, `/v1/extract`,
`/v1/embeddings`) when it gets implemented.

## 19. Coding Conventions

- **PHP/Laravel:** follow `backend/AGENTS.md` (Laravel Boost guidelines) and existing code style:
  curly braces on all control structures, constructor property promotion, explicit return types /
  param types, PHPDoc over inline comments. Use **`spatie/laravel-data`** DTOs in `app/Data/` for
  API response shaping (not Eloquent API Resources). Name them by entity and role:
  `EntitySummaryData` (listing), `EntityDetailData` (detail page / action response) and
  `EntityPreviewData` (embedded related data, e.g. `ResearchedDocumentDetailData` has a
  `FilePreviewData`). Create a DTO only when an endpoint actually needs it; build it with a
  `fromModel()` factory. Use `php artisan make:*` generators. Run Pint before finishing.
- **Vue/TypeScript:** `<script setup>` + Composition API, Pinia stores, typed shared models in
  `src/types/`, shadcn-vue component patterns, Tailwind 4 utility classes, `cn()` from
  `lib/utils.ts`. Keep API access in stores/services — not inside view markup.
- **Python:** keep the FastAPI service small and internal; pydantic models for request/response
  shapes matching `API_SPEC.md` §9.
- Naming: descriptive names (`isRegisteredForDiscounts`, not `discount()`); enums in
  `lower_snake_case` at the API boundary per `API_SPEC.md`.

## 20. Rules for AI Coding Agents

### Before modifying code

1. Inspect the relevant existing implementation first.
2. Read the applicable spec: `docs/API_SPEC.md` and/or `docs/DB_SCHEMA.md`. For context, also
   consult the matching supporting doc: `docs/ARCHITECTURE.md` (design),
   `docs/SECURITY.md` (auth/ownership/validation), `docs/TEST_PLAN.md` (tests),
   `docs/PRODUCT_REQUIREMENTS.md` (scope/intent).
3. Search for existing services, models, jobs, controllers, resources, composables, components,
   and tests — reuse existing patterns; do not create a second implementation of an existing
   responsibility.
4. Do not create new top-level directories or add dependencies without justification.

### When changing APIs

Update all affected layers:

```text
Frontend (types, stores, views)
    ↓
Laravel API (routes, controllers, requests, resources, validation)
    ↓
Database / Queue / FastAPI (migrations, jobs, internal client)
```

Never silently break the API contract. If a contract must change, update `docs/API_SPEC.md` and/or
`docs/DB_SCHEMA.md` in the same change and call it out in your summary.

### When changing database models

Check: migrations, models, relationships, factories, seeders, API resources, validation rules,
tests, and frontend type assumptions. Changing table/enum semantics requires a migration — never
mutate schema meaning in place.

### When changing analysis behavior

Walk the entire pipeline: upload → queue → extraction → persistence → Crossref validation →
embedding → scoring → citation resolution → report generation → completion/failure. Keep
asynchronous processing asynchronous.

### When fixing bugs

Fix the underlying invariant (ownership check, derived-status logic, state transition rule), not
one API response or one UI symptom.

### Avoid

- speculative abstractions and unnecessary rewrites,
- duplicated business logic (especially citation-status derivation and scoring),
- undocumented API changes and invented response envelopes,
- bypassing ownership/authorization checks,
- exposing internal services (FastAPI/GROBID/SBERT) publicly,
- hardcoded secrets, committing `.env` or credentials,
- silently changing enum values (`status`, `analysis_step`, severity, etc.),
- changing database semantics without migrations,
- persisting derived data (citation status) without changing the canonical schema,
- synchronous analysis inside the upload request,
- adding dependencies without justification.

## 21. Safe Change Checklist

Before finishing any task:

1. `AGENTS.md` constraints respected (boundaries, invariants, contracts).
2. All touched paths and commands verified against the repo (no fabricated paths/commands).
3. Behavior verified against `docs/API_SPEC.md`; schema changes verified against
   `docs/DB_SCHEMA.md`; security-sensitive changes checked against `docs/SECURITY.md`;
   new behavior covered per `docs/TEST_PLAN.md`.
4. Backend: `php artisan test` (or targeted `vendor/bin/pest`) passes; `vendor/bin/pint --dirty`
   run.
5. Frontend: `npm run type-check` (and `npm run build`) passes.
6. Ownership isolation and error envelopes intact for every affected endpoint.
7. No secrets committed; `.env` untouched/ignored.
8. Any spec discrepancy encountered is reported in the summary — not silently "fixed".
