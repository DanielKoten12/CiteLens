# Product Requirements — Dafpus Cek

> Product naming varies by artifact: the API contract calls the product **Dafpus Cek**, the
> repository is **Dafpus Sitasi Halu**, and the frontend uses **CiteLens** as UI branding. All
> three refer to the same product.

This document is the requirements baseline derived from the capstone proposal
(`docs/Proposal Capstone Project Kelompok 7.pdf` / `.md`). It states **what** the product must do
and **why**; the **how** lives in `docs/ARCHITECTURE.md`, and the binding interface lives in
`docs/API_SPEC.md`.

Source-of-truth order (see `AGENTS.md` §2):

1. `docs/API_SPEC.md` — public API contract
2. `docs/DB_SCHEMA.md` — database/domain schema (first DBML block)
3. The capstone proposal — product intent and research scope
4. Existing implementation

---

## 1. Problem statement

Generative AI tools are increasingly used to draft academic writing, including theses. They
frequently emit **hallucinated citations** — references that look plausible but do not exist, or
that attach real author names to invented titles. Manual verification is slow and does not scale
to documents with many references.

The product provides an automated verification layer that:

- checks whether each bibliography entry actually exists in external publication metadata,
- checks whether DOIs are valid and consistent with the entry they are attached to,
- checks whether in-text citations have a valid bibliography counterpart,
- highlights problems directly on the document so a user can review them quickly.

**This is not** a plagiarism detector, a grammar checker, or a general academic-writing quality
evaluator. Scope is limited to DOI validation and citation consistency.

---

## 2. Goals

| # | Goal | Measure |
|---|---|---|
| G1 | Detect references whose DOI is invalid or resolves to a different publication. | Precision / Recall / F1 against labelled ground truth |
| G2 | For a well-formed reference without a DOI, find and suggest the most likely DOI. | Precision and Recall of suggested DOI vs. true DOI |
| G3 | Detect in-text citations with no valid bibliography counterpart, or with an incorrect author/year combination. | Precision / Recall / F1 against labelled ground truth |
| G4 | Present findings visually (highlights + issue list) and persist them as history/reports. | Usability acceptance + report availability |

## 3. Non-goals (explicitly out of scope)

- Plagiarism detection.
- Grammar / spelling checking.
- Judging the substantive quality of writing.
- Google Scholar as a metadata source (no official API; scraping is prohibited by ToS).
- DOCX processing in API v1 (see §9 Discrepancies).
- DOCX/Excel report export in v1 (PDF only).

---

## 4. Users and roles

| Persona | Description | Primary needs |
|---|---|---|
| **Unauthorized visitor** | Not logged in. | Register, log in. |
| **Registered user** (student/author) | Uploads a thesis/draft. | Upload, understand findings, review, correct, download a report. |
| **External metadata service** | Crossref (and, per proposal, OpenAlex). | Not a user; a system dependency. |

There is no admin role in v1. Every user sees only their own documents.

---

## 5. Scope

### 5.1 In scope (three canonical detection cases)

1. **Invalid DOI** — a DOI exists in the document but is invalid or points to a different
   publication than the entry claims.
2. **Missing DOI on a valid reference** — the reference is well-formed but has no DOI; the system
   searches Crossref and suggests the most likely DOI.
3. **Citation inconsistency** — an in-text citation has no valid bibliography counterpart, or its
   author/year combination is wrong.

Supporting capabilities:

- PDF upload (≤ 20 MB) with automatic analysis start.
- Extraction of bibliography entries and in-text citations (GROBID) including page coordinates.
- Crossref metadata lookup and candidate ranking.
- Citation resolution (pairing in-text citations to references).
- Findings/highlight feed for the document viewer.
- Manual review of reference verdicts and citation pairing.
- History of analysed documents and async PDF report generation.

### 5.2 Out of scope (v1)

- Content plagiarism and writing-quality checks.
- DOCX upload (API v1 is PDF only).
- OCR of scanned PDFs without a text layer.
- Google Scholar integration.
- Non-PDF report exports.
- Multi-user collaboration / sharing of documents.

---

## 6. Functional requirements

Requirement IDs are grouped by capability and map to the endpoint groups in `docs/API_SPEC.md`.

### 6.1 Authentication

| ID | Requirement |
|---|---|
| FR-A1 | A visitor can register with name, email and password (min 8 chars, confirmed). |
| FR-A2 | A user can log in and receive a bearer token. |
| FR-A3 | An authenticated user can log out; the current token is revoked. |
| FR-A4 | An authenticated user can read their own profile. |
| FR-A5 | Only register/login are public; every other endpoint requires authentication. |

### 6.2 Document upload and lifecycle

| ID | Requirement |
|---|---|
| FR-D1 | A user can upload a PDF (≤ 20 MB) with an optional display name. |
| FR-D2 | Upload persists the document (`pending`, step `queued`) and its file, then dispatches analysis asynchronously and returns `202 Accepted`. |
| FR-D3 | A user can poll analysis status/progress (`GET /documents/{id}/status`). |
| FR-D4 | A user can list their document history with filters (`status`, name search, sort) and pagination. |
| FR-D5 | A user can view a document's detail and, once completed, its summary counts. |
| FR-D6 | A user can retry analysis **only** for a `failed` document; other states return `409`. |
| FR-D7 | A user can hard-delete one document (cascading) or purge their entire history. |

### 6.3 Reference (bibliography) verification

| ID | Requirement |
|---|---|
| FR-R1 | The system extracts bibliography entries with `raw_text`, DOI, title, authors, publication name/year and text offsets. |
| FR-R2 | For each reference the system obtains ranked Crossref candidates. |
| FR-R3 | The system computes a confidence score and assigns a finding status (`pending`/`valid`/`suspicious`/`invalid`/`not_found`) with a human-readable reason. |
| FR-R4 | A user can list references for a document (filter by finding status and DOI presence). |
| FR-R5 | A user can view reference detail including finding, ranked candidates, highlight locations and resolved citations. |
| FR-R6 | A user can manually override a finding (status + selected candidate); the change is flagged `is_manual` and audited (`reviewed_by`, `reviewed_at`). |
| FR-R7 | A valid reference lacking a DOI must yield a suggested DOI as the top candidate. |

### 6.4 Citation verification

| ID | Requirement |
|---|---|
| FR-C1 | The system extracts each in-text citation occurrence with text, optional marker, context and offsets. |
| FR-C2 | The system resolves each citation to a bibliography reference where possible. |
| FR-C3 | A user can list citations for a document (filter by derived status and reference). |
| FR-C4 | A user can view citation detail including highlight locations. |
| FR-C5 | A user can manually pair/unpair a citation with a reference **from the same document**. |
| FR-C6 | Citation status is derived (never stored): `valid`, `unreliable`, `pending`, `hallucination`. |

### 6.5 Findings and reports

| ID | Requirement |
|---|---|
| FR-F1 | A user can fetch a derived, read-only findings/highlight feed combining problem references and problematic citations, filterable by type and severity. |
| FR-F2 | Each finding exposes a text snippet and PDF coordinates for highlighting. |
| FR-F3 | A user can generate a PDF report for a **completed** document (async). |
| FR-F4 | A user can list, view and delete reports. |
| FR-F5 | Report generation is allowed only when the document is `completed`; otherwise `409`. |

---

## 7. Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-1 | **Ownership isolation.** A resource is visible only to the owner of its parent document; foreign access returns `404 NOT_FOUND`, never `403`. |
| NFR-2 | **Asynchronous analysis.** Upload never blocks on the pipeline; progress is observable via status polling. |
| NFR-3 | **Consistent envelopes.** All responses use the documented `data`/`message`/`meta` and `error` shapes. |
| NFR-4 | **Deterministic contracts.** IDs are UUID v4; timestamps are ISO 8601 UTC; confidence is `0.0000`–`1.0000`; enums are `lower_snake_case`. |
| NFR-5 | **Rate limiting.** Login/register 5/min/IP; upload 10/min/user; status 120/min/user; general authenticated 60/min/user. |
| NFR-6 | **Input safety.** Only PDF, ≤ 20 MB; reject other input with the documented 413/415/422 errors. |
| NFR-7 | **Internal services remain private.** GROBID/SBERT are never exposed to the browser. |
| NFR-8 | **No secrets in the repository.** Credentials come from environment/config only. |
| NFR-9 | **Portable persistence.** Migrations must remain portable across SQLite (dev) and MySQL/Postgres (target). |
| NFR-10 | **Reviewability.** Every derived verdict must be explainable via a `reason`/`match_reason`. |

---

## 8. Domain semantics (summary)

Canonical definitions live in `docs/API_SPEC.md` §2.6 and `docs/DB_SCHEMA.md`.

- **Document status:** `pending → processing → completed`, or `failed`.
- **Analysis steps:** `queued → extracting → persisting → crossref_validation → embedding → scoring → resolving_citations → generating_report → completed`.
- **Reference finding status:** `pending`, `valid`, `suspicious`, `invalid`, `not_found`.
- **Derived citation status:** `hallucination` (unpaired), else `valid` / `unreliable` / `pending` from the paired reference finding.
- **Derived severity:** `high` (reference `not_found`/`invalid`, citation `hallucination`), `medium` (reference `suspicious`), `info` (reference `pending`), `low` (reserved).

`completed` does **not** mean "no findings" — findings are the product's output, not a failure.
`failed` means the pipeline aborted and `analysis_error` explains why.

---

## 9. Success metrics (research evaluation)

Per the proposal, the detection quality is evaluated against a labelled ground-truth dataset
using:

- **Confusion matrix:** TP, TN, FP, FN.
- **Precision** = TP / (TP + FP) — avoids over-flagging valid references.
- **Recall** = TP / (TP + FN) — avoids missing real problems.
- **F1-Score** = 2 × (Precision × Recall) / (Precision + Recall).
- Additional **Precision/Recall for the DOI-suggestion case** (suggested DOI vs. true DOI).

Thresholds and weights in the matching algorithm are tuned against these metrics (see
`docs/ARCHITECTURE.md` §7). The proposal's behavioural rule of thumb: a similarity score ≥ 85%
marks an entry as valid; below that, entries from local/non-indexed venues are downgraded to
`suspicious` rather than `not_found` to reduce false positives.

---

## 10. Constraints and dependencies

- PDF + DOCX were intended by the proposal; API v1 supports **PDF only**.
- Citation format validation targets **APA and IEEE**.
- Validation depends on Crossref availability and rate limits (and, per the proposal, OpenAlex —
  not part of the v1 contract).
- Local/Indonesian journals may not be indexed by Crossref and will be reported as
  `not_found`/`suspicious`, which is not proof of fabrication.
- Scanned PDFs without a usable text layer cannot be processed.

---

## 11. Known specification discrepancies

Do not silently resolve these; implement against the canonical contract and flag the conflict.

1. **DOCX:** proposal scopes PDF + DOCX; `API_SPEC.md` v1 is PDF only.
2. **OpenAlex:** proposal allows Crossref + OpenAlex; `API_SPEC.md` defines Crossref only.
3. **DB_SCHEMA.md** contains a second stale DBML block; the **first** block is canonical.
4. **Citation styles:** proposal limits format validation to APA and IEEE.
5. **Frontend prototype types** (`valid | warning | halu`) do not match API enums — the API wins.
6. **Database engine:** specs target MySQL/Postgres; current dev/test default is SQLite.

---

## 12. Acceptance criteria

A release is acceptable when:

1. All functional requirements above are implemented against `docs/API_SPEC.md`.
2. Ownership isolation holds for every document-owned resource (cross-user access → `404`).
3. Upload validation returns the documented `413`/`415`/`422` envelopes.
4. The pipeline runs asynchronously with observable progress and correct terminal states.
5. Retry, manual review and citation pairing obey their state/ownership rules.
6. Citation status is derived in one shared place and updates immediately after manual review.
7. The test plan in `docs/TEST_PLAN.md` passes, including the evaluation metrics harness.
