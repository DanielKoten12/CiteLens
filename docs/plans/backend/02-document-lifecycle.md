# Phase 02 — Document Lifecycle Endpoints

> **Status:** implemented via [`02-document-lifecycle-detail.md`](02-document-lifecycle-detail.md) · **Depends on:** Phase 01 · **Unblocks:** Phase 03
> Canonical references: `docs/API_SPEC.md` §4/§2.8/§2.9, `docs/DB_SCHEMA.md` (`researched_documents`,
> `files`), `docs/TEST_PLAN.md` T-DOC / T-OWN, `AGENTS.md` §8.

---

## 1. Objective

Implement the full `/documents` surface (list, detail, status polling, retry, hard delete, purge),
fix the upload slice so it dispatches the analysis job, and produce the derived document summary.
The pipeline internals are Phase 03; this phase ensures the lifecycle around them is correct,
scoped and testable with factories.

---

## 2. Scope

**In:** `DocumentController` + routes; `ListDocumentsRequest`; document DTOs (summary/status,
detail extension); document query service; summary service; retry/reset; delete/purge with file
cleanup; upload dispatch + canonical message; rate limiters on upload/status.

**Out:** `AnalyzeDocumentJob` internals (Phase 03), reference/citation/report endpoints (Phases
05/06), any schema change beyond an approved OQ migration.

---

## 3. Deliverables

### 3.1 Controllers and routes

`App\Http\Controllers\Api\DocumentController`:

| Action | Route | Status | Notes |
|---|---|---|---|
| `index` | `GET /api/v1/documents` | 200 | filters `status`, `q`, `sort`; paginated |
| `show` | `GET /api/v1/documents/{document}` | 200 | full detail (+ `summary`; `null` until completed) |
| `status` | `GET /api/v1/documents/{document}/status` | 200 | lightweight polling payload |
| `retry` | `POST /api/v1/documents/{document}/retry` | 202 | only from `failed`, else 409 |
| `destroy` | `DELETE /api/v1/documents/{document}` | 204 | cascade + file cleanup |
| `purge` | `DELETE /api/v1/documents` | 204 | entire owned history |

Route requirements:

- All under `auth:sanctum` + `throttle:api` (existing group).
- `POST /documents` additionally `throttle:documents` (10/min/user).
- `GET /documents/{document}/status` additionally `throttle:document-status` (120/min/user).
- `whereUuid('document')` on all UUID params; names `v1.documents.*`.
- Declare `DELETE /documents` (purge) and `DELETE /documents/{document}` as clearly grouped, separate routes; keep the static purge route explicit so it can never be shadowed by a future wildcard.

`UploadController::upload` changes:

- After the upload service commits, dispatch `AnalyzeDocumentJob` with `afterCommit()` semantics
  (`dispatch()->afterCommit()` or `DB::afterCommit(...)`) — never inside an open transaction.
- Message becomes the canonical `"Dokumen berhasil diunggah. Analisis sedang diproses."`
  (`docs/API_SPEC.md` §4). Update `UploadDocumentTest`.
- Response remains `202` with the existing detail shape.
- OQ-01 resolved: upload never returns `503`; inference unavailability is reported by the job via
  `status=failed` + safe `analysis_error`. The optional preflight
  (`analysis.require_inference_at_upload=false`) stays off by default.

### 3.2 Requests — `app/Http/Requests/Document/`

- `ListDocumentsRequest`: `status` (nullable, enum), `q` (nullable string ≤ 255), `sort`
  (nullable, in `created_at|-created_at`, default `-created_at`), `page`, `per_page`
  (1–100, default 15). Invalid values → `422` via the canonical renderer.
- Retry/delete/status need no body; rely on the state guard + scoping.

### 3.3 DTOs — `app/Data/ResearchedDocument/`

- `DocumentAnalysisSummaryData` — exactly the fields in `docs/API_SPEC.md` §4:
  `total_references`, `valid`, `suspicious`, `invalid`, `not_found`, `total_citations`,
  `valid_citations`, `unreliable_citations`, `pending_citations`, `hallucination_citations`.
- `ResearchedDocumentSummaryData` — `id`, `name`, `status`, `progress`, `summary`, `created_at`,
  `updated_at` (list item).
- `ResearchedDocumentStatusData` — `id`, `status`, `progress`, `current_step`, `error`,
  `updated_at`.
- Extend `ResearchedDocumentDetailData` with `summary`: `null` until `completed` (OQ-10 resolved).
  Keep `file` + progress/step/error so detail stays the "full" shape.

### 3.4 Services

| Service | Responsibility |
|---|---|
| `Services\Document\DocumentQueryService` | owned query, filters, deterministic sort + tiebreaker, pagination; eager-loads `file` |
| `Services\Document\DocumentSummaryService` | derived counts for one document and batched counts for a list (no N+1) |
| `Services\Document\DocumentLifecycleService` | retry guard/reset/dispatch; terminal-state transitions used by the pipeline |
| `Services\Document\DocumentAnalysisResetService` | deletes previous run's derived rows in FK-safe order |
| `Services\Document\DocumentDeletionService` | single delete + purge, DB cascade + explicit file cleanup |
| `Services\Document\DocumentFileManager` | store/delete document & report file rows + objects (shared with Phase 06) |

Design guidance:

- **Summary is derived, never stored.** Compute with grouped aggregate queries; for `index`,
  batch all completed documents in one query pair (references by finding status, citations by
  derived status) keyed by document id.
- Derived citation counts must reuse the same rules as Phase 05's resolver; if the resolver is not
  ready yet, implement the SQL buckets here and let Phase 05 share/extract them — never duplicate
  the rule.
- **Retry reset order** (FK-safe): citation locations → citations → reference locations →
  candidate rows → findings → references, inside one transaction; then reset
  `status=pending`, `analysis_progress=0`, `analysis_step=queued`, `analysis_error=null`,
  `analysis_started_at=null`, `analysis_completed_at=null`. Do not delete the uploaded file.
- **Delete/purge file cleanup.** `files` is polymorphic with no FK, so:
  1. collect every `files` row for the document and for each of its reports,
  2. transaction: delete `files` rows + `$document->delete()` (DB cascades the rest),
  3. after commit: delete the stored objects best-effort; log failures and surface them
     operationally (never silently leave orphans).
  `purge` reuses the same service per document via `lazyById()`/chunking; it must not load the
  whole history at once.
- **Status endpoint stays cheap**: select only the six fields, no relations, no summary.
- Stable ordering: append `id` as a tiebreaker to `created_at` sorts so pagination cannot skip
  rows.

### 3.5 Rate limits

Add `throttle:documents` to `POST /documents` and `throttle:document-status` to the status route
(limiters created in Phase 01). Keep the general `throttle:api` on both (the tighter limit wins).

---

## 4. Contracts & invariants

- Retry is allowed **only** from `failed`; any other state → `409 CONFLICT` with the spec message.
- `completed` never means "clean document"; it means the pipeline finished.
- Purge deletes only the authenticated user's documents.
- Foreign document → `404 NOT_FOUND` with `"Dokumen tidak ditemukan."`, never `403`.
- Upload is never synchronous and never returns before persistence commits.
- `summary` is only meaningful for `completed` (OQ-10 defines the representation).

---

## 5. Tests / acceptance criteria

| Test ID | Case | Expected |
|---|---|---|
| T-DOC-01..02 | Upload ≤ 20 MB / custom name | `202`, `pending`/`queued`, file stored, custom name |
| T-DOC-03..08 | 415 / 413 / 422 / 401 / storage failure | existing coverage stays green; no upload-time 503 (OQ-01) |
| T-DOC-09 | `AnalyzeDocumentJob` dispatched | `Bus::fake()` assertion (also T-PIPE-05) |
| T-DOC-10 | List filters/sort/pagination | correct `data` + `meta`, default `-created_at`, invalid filters `422` |
| T-DOC-11 | Detail shape | completed includes `summary`; non-completed → `summary: null` (OQ-10) |
| T-DOC-12 | Status payload | `id/status/progress/current_step/error/updated_at` only |
| T-DOC-13 | Retry from `failed` | `202`, reset to `pending`/`queued`, job dispatched, old rows gone |
| T-DOC-14 | Retry from `pending`/`processing`/`completed` | `409 CONFLICT` + spec message |
| T-DOC-15 | Delete one | `204`, DB cascades, files row + object gone |
| T-DOC-16 | Purge | `204`, all owned docs gone, other users untouched |
| T-DOC-17 | Upload/status rate limits | `429 RATE_LIMITED` at 10/min and 120/min |
| T-OWN-01..04 | Foreign document access (show/status/retry/delete) | `404`, body does not confirm existence |
| T-OWN-09 | Cross-user purge isolation | user B's documents unaffected |
| F-SUM-01 | Summary counts | correct `valid/suspicious/invalid/not_found` and all four citation buckets |
| F-SUM-02 | List without N+1 | summary batch query count stable as document count grows |

Exit: all T-DOC/T-OWN rows above pass; upload message test updated; `pint` clean.

---

## 6. Decisions (resolved)

- **OQ-01** — upload always returns `202` after persistence; no preflight by default (opt-in config
  only). Inference unavailability surfaces as `failed` + safe `analysis_error`.
- **OQ-10** — `summary: null` until `completed`.

---

## 7. Risks

- Duplicate job dispatch on retry/upload (e.g. double click) — rely on Phase 03's job uniqueness +
  `WithoutOverlapping`, but add a test for the retry guard.
- Deleting composited documents while a job runs: job must tolerate a missing document
  (`ModelNotFoundException` → abort quietly); see Phase 03.
- Batched summary queries can drift from the single-document summary; test both paths against the
  same fixture.
