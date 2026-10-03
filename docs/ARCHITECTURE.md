# Architecture — Dafpus Cek

This document describes the system's components, responsibility boundaries, data flow and
deployment topology. The **public contract** is `docs/API_SPEC.md`; the **domain schema** is
`docs/DB_SCHEMA.md`. Where this document and those disagree, the canonical specs win.

---

## 1. System context

```text
┌──────────────┐        REST / JSON (public, /api/v1)        ┌─────────────────────┐
│  Vue 3 SPA   │ ─────────────────────────────────────────▶ │   Laravel API       │
│  (CiteLens)  │ ◀───────────────────────────────────────── │  auth · orchestration│
└──────────────┘                                            │  scoring · reports  │
                                                            └──────────┬──────────┘
                                                                       │
                        ┌──────────────────────────────┬───────────────┼───────────────────┐
                        ▼                              ▼               ▼                   ▼
                 MySQL / Postgres              Queue worker     FastAPI inference    Crossref REST
                 (docs/DB_SCHEMA.md)          (async pipeline)  (internal network)   (outbound HTTP)
                                                                      │
                                                          ┌───────────┴───────────┐
                                                          ▼                       ▼
                                                       GROBID                   SBERT
                                                (refs + citations +        (sentence
                                                 coordinates)              embeddings)
```

- **Laravel is the source of truth.** It owns auth, persistence, orchestration, scoring and all
  public endpoints.
- **FastAPI (`inference/`) is an internal inference server only.** It is not part of the public
  contract and must never be called by the browser.
- **Crossref** is fetched outbound by Laravel only (queue workers).

---

## 2. Responsibility boundaries

| Concern | Owner | Notes |
|---|---|---|
| Auth, authorization, persistence, document lifecycle | Laravel | Sanctum tokens; ownership scoped queries |
| Orchestration, status/progress | Laravel | Queue job + `analysis_*` columns |
| Crossref lookups, candidate ranking/scoring | Laravel | Never from the frontend |
| String similarity (Jaro-Winkler, Levenshtein), exact matching | Laravel | Scoring stays server-side |
| Document parsing, reference/citation extraction, coordinates | FastAPI + GROBID | Internal |
| Semantic embeddings (SBERT) | FastAPI + SBERT | Internal, batched |
| API response formatting, manual review, report orchestration | Laravel | spatie/laravel-data DTOs (`backend/app/Data/`) |
| Rendering verdicts, highlights, history | Vue SPA | Never decides validity |

Hard rules:

1. GROBID/SBERT functionality is never exposed as public API endpoints.
2. The frontend never accesses the database, GROBID, SBERT or Crossref directly.
3. The frontend never decides whether a reference is valid; it renders Laravel's verdicts.
4. Scoring and citation-status derivation are implemented **once**, server-side.

---

## 3. Technology stack

| Layer | Technology |
|---|---|
| Frontend | Vue 3 + TypeScript, Vite 6, Pinia, vue-router, Tailwind 4, shadcn-vue (`reka-ui`), `pdfjs-dist` |
| Backend | PHP 8.3+ (local CLI 8.5), Laravel 13, spatie/laravel-data 4 (DTOs), Laravel Sanctum, Pest 5, Pint |
| Database | MySQL/Postgres (target); SQLite for local dev and tests |
| Queue | Laravel queue (`QUEUE_CONNECTION=database` by default; `sync` in tests) |
| Inference | Python 3.14, FastAPI (`fastapi[standard]`), uv-managed; GROBID + SBERT |
| External | Crossref REST API |

---

## 4. Repository layout

```text
/
├── docs/                      # canonical specs + this architecture/requirements set
├── frontend/                  # Vue 3 SPA ("CiteLens")
│   └── src/{views,components,stores,router,types,lib}
├── backend/                   # Laravel application
│   ├── routes/{web.php,api.php}
│   ├── app/{Http,Models,Services,Jobs,Providers,Exceptions}
│   ├── config/
│   └── database/{migrations,factories,seeders}
└── inference/                 # FastAPI internal inference service
    ├── main.py
    └── src/inference/
```

Backend entry points: `backend/bootstrap/app.php` (middleware/exception bootstrap),
`backend/routes/api.php` (API routes under `/api/v1`). The FastAPI entry point is
`inference/main.py`.

---

## 5. Backend layered design

```text
routes/api.php
      │
      ▼
Http/Controllers/Api/*            ← transport: request → resource + HTTP status
      │  (FormRequest validation)   (Http/Requests/*)
      ▼
Services/*                        ← orchestration, transactions, domain rules
      │
      ├── Models/* (Eloquent)      ← persistence + relationships
      ├── Jobs/* (queued)          ← async analysis / report generation
      └── Inference client         ← HTTP to FastAPI (internal)
```

Conventions:

- **Form Requests** own validation and map failures to the canonical error envelope
  (e.g. `UploadDocumentRequest` maps `Max` → `413 PAYLOAD_TOO_LARGE`, `Mimes`/`Mimetypes` →
  `415 UNSUPPORTED_MEDIA_TYPE`, else `422 VALIDATION_ERROR`).
- **Services** own transactions and cross-model invariants. `DocumentUploadService` writes the
  document + file transactionally and cleans up the stored file if the DB write fails.
- **Data DTOs** (`app/Data/`, **`spatie/laravel-data`**) own response shaping
  (`ResearchedDocumentDetailData`, `FilePreviewData`, etc.) so the envelope stays consistent.
  Name DTOs by entity and role — `EntitySummaryData` (lists), `EntityDetailData` (detail/action
  responses), `EntityPreviewData` (related data embedded in another DTO) — and only add one when
  an endpoint needs it. Use injectors, casts, castables, transformer when needed instead of manual
  logic inside a FromModel static function.
- **Exceptions** render the canonical envelope next to their definition
  (`DocumentUploadFailedException::render()`).

## 6. Frontend design

- `<script setup>` + Composition API; Pinia stores for state; typed models in `src/types/`.
- Routing + auth guard in `src/router/index.ts` (`public` routes for login/register/forgot;
  everything else requires auth).
- API access belongs in stores/services — **not** inside view markup.
- The document viewer uses `pdfjs-dist`; highlight boxes come from backend location records
  (`pdf_points_top_left` coordinate system, 1-based pages).
- **Current status:** `src/stores/auth.ts` is a mock (no API calls yet) and `src/types/index.ts`
  uses prototype types (`valid | warning | halu`, numeric ids, `trustScore`) that predate the API
  contract. Treat them as disposable UI scaffolding; map to canonical UUIDs/enums when wiring the
  API. The API base URL is not wired yet — use a `VITE_API_BASE_URL`-style variable when it is.

---

## 7. Analysis pipeline

Upload and analysis start are intentionally combined in `POST /api/v1/documents`.

```text
POST /documents
  1. store PDF + create researched_documents (status=pending, step=queued) + files row
  2. dispatch AnalyzeDocumentJob → respond 202 immediately
       │
       ▼  (queue worker)
  3. FastAPI POST /v1/extract (GROBID)
        → insert references + reference locations
        → insert citations + citation locations
  4. per reference: Crossref lookup (DOI or bibliographic search)
        → insert ranked reference_finding_candidates
  5. scoring (Laravel): Jaro-Winkler + Levenshtein + SBERT embeddings
        → confidence, selected_candidate_id → upsert reference_findings
  6. resolve citations → set researched_document_citations.researched_document_reference_id
        → unpaired citations surface as hallucination
  7. compute summary counts → status=completed
  8. on unrecoverable error → status=failed + analysis_error
```

The worker updates `analysis_progress` (0–100) and `analysis_step` as it advances. The canonical
step sequence is:
`queued → extracting → persisting → crossref_validation → embedding → scoring → resolving_citations → generating_report → completed`.

Clients poll `GET /documents/{id}/status` (~2 s, backing off to 5 s after 30 s) and stop on
`completed`/`failed`. Progress must never be produced synchronously inside the upload request.

### 7.1 Scoring model

The matching algorithm combines several signals; weights/thresholds are configurable and tuned
via the evaluation metrics in `docs/TEST_PLAN.md`.

| Signal | Method | Applied to |
|---|---|---|
| Exact match | equality | DOI, year |
| Character similarity | Levenshtein (normalised) | journal/source names |
| Author similarity | Jaro-Winkler | author names |
| Semantic title similarity | SBERT embeddings + cosine similarity | titles |
| Metadata agreement | field-by-field comparison | authors, year, source |

A DOI that resolves but whose metadata conflicts with the entry must be distinguishable from a
DOI that does not resolve at all (case 1 vs. case 2 in `PRODUCT_REQUIREMENTS.md`). SBERT output is
only one indicator and must be combined with the other signals — it is never sufficient on its
own.

---

## 8. Async processing and state transitions

```text
pending ──▶ processing ──▶ completed
                    └────▶ failed ──(retry)──▶ pending
```

- Terminal `completed` includes documents that have findings.
- `POST /documents/{document}/retry` is valid **only** from `failed`; otherwise `409 CONFLICT`.
- Reports follow `pending → processing → completed | failed`, one report per document, generated
  only for completed documents.

Tests configure `QUEUE_CONNECTION=sync` (see `backend/phpunit.xml`); production uses the database
queue. Code must not assume either.

---

## 9. Data model (overview)

Canonical schema: `docs/DB_SCHEMA.md` (first DBML block). All PKs are UUID v4; deletion is a hard
cascade.

```text
users
 └── researched_documents ── files (polymorphic: fileable_type/fileable_id)
        ├── researched_document_references
        │      ├── researched_document_reference_locations
        │      └── reference_findings
        │             └── reference_finding_candidates
        ├── researched_document_citations (nullable → reference)
        │      └── researched_document_citation_locations
        └── generated_document_reports ── files
```

Key rules:

- **Citation status is derived, never stored.** Compute it in one shared place (accessor/resource)
  from the pairing + paired reference finding status. Manual review therefore immediately changes
  every dependent citation's status.
- **Locations** store 1-based page numbers and bounding boxes in `pdf_points_top_left`; do not
  change coordinate semantics casually.
- **Findings** carry the verdict + audit fields; **candidates** carry the ranked alternatives.

---

## 10. Inference service contract (internal)

Base URL `http://inference:8000` (private network, no auth in v1). Defined in `API_SPEC.md` §9.

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | `{ status, grobid, sbert }` liveness |
| POST | `/v1/extract` | Multipart PDF → references + citations + coordinates (GROBID) |
| POST | `/v1/embeddings` | Batch texts → vectors (SBERT) |

Batch embedding requests (e.g. 32 texts) to bound latency/memory. Crossref consolidation is not
delegated to GROBID — Laravel controls validation so results stay reproducible. Failures surface
to the pipeline as `INFERENCE_UNAVAILABLE`.

---

## 11. Configuration and environment

- Backend template: `backend/.env.example` (DB, session, queue, cache, mail). `backend/.env` is
  git-ignored — never read or commit it.
- External services are configured through Laravel config (`backend/config/services.php`) plus
  `.env.example` placeholders (e.g. `CROSSREF_API_KEY=<configured through environment>`).
- Frontend API base URL should come from a `VITE_*` variable, not a hardcoded URL.
- Inference is uv-managed (`pyproject.toml`, `uv.lock`, `.python-version` = 3.14).

Never commit secrets, tokens or credentials.

---

## 12. Deployment topology

| Component | Network exposure |
|---|---|
| Vue SPA | Public (static hosting) |
| Laravel API | Public, behind HTTPS; `/api/v1` |
| Queue worker | Private; same codebase, `queue:work` |
| Database | Private |
| FastAPI inference | **Private only** (not internet-facing) |
| Crossref | Outbound HTTPS from workers |

Report and document files are stored on a private disk; access is via signed/temporary URLs
(`FilePreviewData->url`).

---

## 13. Current implementation status

Verified against the repository:

- **Backend** is a Laravel 13 app. Present: `User`, `ResearchedDocument`, `File` models; the
  canonical domain migration (`create_initial_tables`); Sanctum auth
  (`AuthController`, `AuthService`, `RegisterRequest` / `LoginRequest`, `AuthenticationData` /
  `UserDetailData`, `ErrorResponseData`, `InvalidCredentialsException`) with the `/auth/*` routes; and the upload slice
  (`UploadController`, `UploadDocumentRequest`, `ResearchedDocumentDetailData` /
  `FilePreviewData`, `DocumentUploadService`, `DocumentUploadFailedException`) in
  `routes/api.php`. **Missing:** all other endpoints, `AnalyzeDocumentJob`, the Crossref client,
  scoring, the inference client, and report generation.
- **Frontend** is a working Vue 3 + Vite SPA with mocked auth and prototype types; the API is not
  wired yet.
- **Inference** is a FastAPI hello-world stub; `/health`, `/v1/extract`, `/v1/embeddings` are
  specified but unimplemented.

Everything described as "the pipeline", "scoring" or "reports" above is the **target** design;
only the upload slice exists today.

---

## 14. Design decisions and invariants

1. Upload and analysis start are one endpoint by design; keep processing asynchronous.
2. Laravel owns the decision; FastAPI only extracts and embeds.
3. Derive citation status; do not persist it.
4. Return `404` (not `403`) for foreign resources to avoid disclosing existence.
5. Hard delete with cascade — no soft deletes unless the schema is deliberately changed.
6. Keep migrations portable (SQLite dev, MySQL/Postgres target).
7. One shared implementation per business rule (scoring, citation derivation, envelope shaping).
8. Contract changes require updating `docs/API_SPEC.md` and/or `docs/DB_SCHEMA.md` in the same
   change.
