# Test Plan — Dafpus Cek

This plan defines how the product is verified. It follows the priorities in `AGENTS.md` §18 and
the contracts in `docs/API_SPEC.md` / `docs/DB_SCHEMA.md`.

---

## 1. Objectives

1. Prove the public API behaves exactly as `docs/API_SPEC.md` specifies.
2. Prove **ownership isolation** on every document-owned resource.
3. Prove the async analysis lifecycle and its state transitions.
4. Prove detection behaviour (DOI validation, DOI suggestion, citation consistency) against a
   labelled ground-truth dataset using precision/recall/F1.
5. Prove error envelopes, validation and rate limits.
6. Keep the internal inference contract stable.

## 2. Scope

**In scope:** backend API (Pest feature/unit), domain schema/migrations, scoring logic, queue
orchestration, inference service contract, frontend type-check/build, and the research evaluation
harness.

**Out of scope for automated tests:** visual design fidelity, third-party service uptime,
real Crossref/LLM quality beyond what fixtures cover.

---

## 3. Test levels

| Level | Tool | Location | Purpose |
|---|---|---|---|
| Unit | Pest | `backend/tests/Unit` | Pure logic: similarity, scoring, status derivation |
| Feature | Pest | `backend/tests/Feature` | HTTP endpoints, auth, DB, queue, envelopes |
| Contract | Pest (+ HTTP fake) | `backend/tests/Feature` | Inference client + Crossref client shapes |
| Inference | pytest (to add) | `inference/tests` | `/health`, `/v1/extract`, `/v1/embeddings` |
| Frontend | `vue-tsc` | `frontend` | Type safety (`npm run type-check`, `npm run build`) |
| Evaluation | script/harness | dataset | Precision/Recall/F1/confusion matrix |

### 3.1 Running tests

```bash
# Backend
cd backend
php artisan test                       # or: composer test / vendor/bin/pest
vendor/bin/pest tests/Feature/UploadDocumentTest.php
php artisan test --compact --filter=name
vendor/bin/pint --dirty                # format after edits

# Frontend
cd frontend
npm run type-check
npm run build

# Inference (once real endpoints exist)
cd inference
uv run pytest
```

`backend/phpunit.xml` runs tests on in-memory SQLite with a **sync** queue. Tests must not depend
on a running worker or external network.

---

## 4. Test data and fixtures

- Use model factories (`UserFactory`, `ResearchedDocumentFactory`, `FileFactory`) and
  `RefreshDatabase` (or the existing `DomainSchemaTest` pattern) for isolation.
- `Storage::fake()` for uploads; assert stored paths and cleanup on failure.
- `Queue::fake()` / `Bus::fake()` to assert job dispatch without executing the pipeline.
- `Http::fake()` for Crossref; return recorded JSON fixtures (DOI hit, bibliographic search,
  empty result, mismatch).
- Inference: a fake in-process server or `Http::fake()` returning `/v1/extract` and
  `/v1/embeddings` payloads.
- Ground truth: a small labelled dataset where each reference/citation is annotated as
  correct/incorrect and, for the suggestion case, with the true DOI.

---

## 5. Test matrix

### 5.1 Authentication (`T-AUTH`)

| ID | Case | Expected |
|---|---|---|
| T-AUTH-01 | Register valid payload | `201`, user + token + `Bearer` |
| T-AUTH-02 | Register duplicate email | `422 VALIDATION_ERROR` |
| T-AUTH-03 | Register password mismatch / too short | `422` |
| T-AUTH-04 | Login valid credentials | `200`, token issued |
| T-AUTH-05 | Login wrong password / unknown email | `422` (indistinguishable) |
| T-AUTH-06 | Logout revokes current token | `200`; token rejected afterwards (`401`) |
| T-AUTH-07 | `GET /auth/me` with/without token | `200` / `401` |
| T-AUTH-08 | Protected route without token | `401 UNAUTHENTICATED` |
| T-AUTH-09 | Login/register throttling | `429 RATE_LIMITED` after 5/min |

### 5.2 Upload and document lifecycle (`T-DOC`)

| ID | Case | Expected |
|---|---|---|
| T-DOC-01 | Upload PDF ≤ 20 MB | `202`, `pending`/`queued`, file stored |
| T-DOC-02 | Custom `name` used | `name` = provided value |
| T-DOC-03 | Non-PDF | `415 UNSUPPORTED_MEDIA_TYPE` |
| T-DOC-04 | > 20 MB | `413 PAYLOAD_TOO_LARGE` |
| T-DOC-05 | Missing file | `422 VALIDATION_ERROR` |
| T-DOC-06 | Over-long name | `422` |
| T-DOC-07 | Unauthenticated upload | `401` |
| T-DOC-08 | Storage/DB failure | `500 SERVER_ERROR`, no orphan file/row |
| T-DOC-09 | `AnalyzeDocumentJob` dispatched on upload | asserted via `Bus::fake()` |
| T-DOC-10 | List history: filters/sort/pagination | correct `data`+`meta`, default `-created_at` |
| T-DOC-11 | Detail shape (completed includes `summary`) | matches spec |
| T-DOC-12 | Status polling payload | `status`/`progress`/`current_step`/`error` |
| T-DOC-13 | Retry from `failed` | `202`, reset to `pending`/`queued` |
| T-DOC-14 | Retry from `pending`/`processing`/`completed` | `409 CONFLICT` |
| T-DOC-15 | Delete one document | `204`, cascades |
| T-DOC-16 | Purge history | `204`, all owned docs gone |
| T-DOC-17 | Upload rate limit (10/min/user) | `429` |

### 5.3 Ownership isolation (`T-OWN`) — release blocker

For **every** document-owned endpoint, a second user requesting the first user's resource must
receive `404 NOT_FOUND` (never `403`).

| ID | Case | Expected |
|---|---|---|
| T-OWN-01 | `GET /documents/{other}` | `404` |
| T-OWN-02 | `GET /documents/{other}/status` | `404` |
| T-OWN-03 | `POST /documents/{other}/retry` | `404` |
| T-OWN-04 | `DELETE /documents/{other}` | `404` |
| T-OWN-05 | References/citations/findings of another document | `404` |
| T-OWN-06 | `PATCH /references/{other}/finding` | `404` |
| T-OWN-07 | `PATCH /citations/{other}` | `404` |
| T-OWN-08 | Reports of another document; `GET/DELETE /reports/{other}` | `404` |
| T-OWN-09 | Response body does not confirm existence | no distinguishing detail |

### 5.4 References and findings (`T-REF`)

| ID | Case | Expected |
|---|---|---|
| T-REF-01 | List references with `status`/`has_doi` filters | filtered, paginated |
| T-REF-02 | Reference detail includes finding + candidates + locations + citations | matches spec |
| T-REF-03 | `PATCH` finding sets `is_manual`/`reviewed_by`/`reviewed_at` | `200`, audited |
| T-REF-04 | `PATCH` with invalid `status` | `422` |
| T-REF-05 | `PATCH` with candidate from another finding | `422` |
| T-REF-06 | Finding status → citation derived status propagation | all paired citations update |
| T-REF-07 | DOI resolves to different publication | `invalid` with reason |
| T-REF-08 | Valid reference without DOI | top candidate carries suggested DOI |

### 5.5 Citations and derivation (`T-CIT`)

| ID | Case | Expected |
|---|---|---|
| T-CIT-01 | List with `status`/`reference_id` filters | filtered correctly |
| T-CIT-02 | Citation detail includes locations | matches spec |
| T-CIT-03 | Pair unpaired citation with same-document reference | `200`, derived `valid`/`unreliable` |
| T-CIT-04 | Pair with reference from a **different** document | `422` |
| T-CIT-05 | Unpair citation | derived `hallucination` |
| T-CIT-06 | Derived status: paired + finding `valid`/`suspicious` | `valid` |
| T-CIT-07 | Derived status: paired + finding `invalid`/`not_found` | `unreliable` |
| T-CIT-08 | Derived status: paired + finding `pending` | `pending` |
| T-CIT-09 | Derived status: unpaired | `hallucination` |

### 5.6 Findings feed (`T-FIND`)

| ID | Case | Expected |
|---|---|---|
| T-FIND-01 | Feed includes `reference_*`, `citation_*` types | correct types/ids |
| T-FIND-02 | Severity mapping | `not_found`/`invalid`/`hallucination` → high; `suspicious` → medium; `pending` → info |
| T-FIND-03 | `type`/`severity` filters | 422 on invalid, filtered on valid |
| T-FIND-04 | Locations present for highlight rendering | page + bbox fields |

### 5.7 Reports (`T-REP`)

| ID | Case | Expected |
|---|---|---|
| T-REP-01 | Generate for `completed` document | `202 pending`, job dispatched |
| T-REP-02 | Generate for non-completed document | `409 CONFLICT` |
| T-REP-03 | List / detail / delete | matches spec, `204` on delete |
| T-REP-04 | Failed generation records `error` | status `failed` + `error` |
| T-REP-05 | Report file removed on delete | no orphan in storage |

### 5.8 Scoring and matching (`T-SCORE`) — unit

| ID | Case | Expected |
|---|---|---|
| T-SCORE-01 | Levenshtein normalisation | known pairs → expected similarity |
| T-SCORE-02 | Jaro-Winkler author match | "Koten" vs "Koton" high |
| T-SCORE-03 | SBERT unavailable | falls back to string signals (`503`-safe) |
| T-SCORE-04 | Combined score ordering | higher-confidence candidate ranks first |
| T-SCORE-05 | Threshold boundary (≥ 0.85 valid) | status transitions correct |
| T-SCORE-06 | Local-journal low score | `suspicious`, not `not_found` |
| T-SCORE-07 | Candidate ranks unique per finding | DB constraint enforced |

### 5.9 Pipeline and queue (`T-PIPE`)

| ID | Case | Expected |
|---|---|---|
| T-PIPE-01 | Job walks all steps and sets `completed` | `analysis_progress=100`, step `completed` |
| T-PIPE-02 | GROBID failure | `failed` + `analysis_error`, `503` semantics |
| T-PIPE-03 | CrossRef failure/partial | recorded per reference, pipeline continues |
| T-PIPE-04 | Progress monotonic 0→100 | assert step/timing |
| T-PIPE-05 | Upload does not run pipeline synchronously | response timing / `Bus::fake()` |

### 5.10 Internal inference contract (`T-INF`)

| ID | Case | Expected |
|---|---|---|
| T-INF-01 | `GET /health` | `{status, grobid, sbert}` |
| T-INF-02 | `POST /v1/extract` valid PDF | references + citations + locations |
| T-INF-03 | `POST /v1/extract` non-PDF | `422` |
| T-INF-04 | `POST /v1/extract` GROBID down | `503 EXTRACTION_FAILED` |
| T-INF-05 | `POST /v1/embeddings` batch | dimensions + count match, batching respected |
| T-INF-06 | `POST /v1/embeddings` empty | `422` |
| T-INF-07 | `POST /v1/embeddings` model down | `503 INFERENCE_FAILED` |

### 5.11 Schema (`T-SCHEMA`)

| ID | Case | Expected |
|---|---|---|
| T-SCHEMA-01 | All canonical tables exist | migration creates them |
| T-SCHEMA-02 | UUID v4 primary keys | generated for new rows |
| T-SCHEMA-03 | Hard-delete cascade | dependents removed |
| T-SCHEMA-04 | Deleting a reference unpairs its citations | `researched_document_reference_id` null |

### 5.12 Frontend (`T-FE`)

| ID | Case | Expected |
|---|---|---|
| T-FE-01 | `npm run type-check` | passes |
| T-FE-02 | `npm run build` | passes |
| T-FE-03 | API types match canonical enums/UUIDs | review after each contract change |

---

## 6. Evaluation harness (research metrics)

Separate from correctness tests, the proposal requires measuring detection quality on a labelled
dataset.

- Build a ground-truth set of references and citations labelled valid/invalid (and true DOI for
  the suggestion case).
- Run the detection pipeline and compare predictions to labels.
- Compute, per case and overall:

```text
Precision = TP / (TP + FP)
Recall    = TP / (TP + FN)
F1        = 2 * (Precision * Recall) / (Precision + Recall)
```

- Report the confusion matrix (TP/FN/FP/TN) for case 1 and case 3; report Precision/Recall of the
  suggested DOI for case 2.
- Use the results to tune thresholds/weights (see `docs/ARCHITECTURE.md` §7.1). Record the
  threshold configuration used for each reported run.

---

## 7. Non-functional verification

| Area | Check |
|---|---|
| Envelopes | Every success/collection/error response matches `API_SPEC.md` §2.4/§2.5 |
| Formats | UUID v4 ids; ISO 8601 UTC timestamps; confidence 4 decimals; `lower_snake_case` enums |
| Pagination | default 15, max 100; invalid filters → `422` |
| Rate limits | all four scopes enforced; `429` envelope |
| Async | upload returns immediately; progress observable; no sync processing |
| Portability | migrations run on SQLite (dev/test) without engine-specific SQL |
| Security | `docs/SECURITY.md` checklist passes |

### 7.1 Performance smoke (targets, not hard gates)

- Upload endpoint responds `202` without waiting for analysis.
- Status polling is cheap and stable at 120/min/user.
- Embedding requests are batched (e.g. 32 texts) to bound latency/memory.

---

## 8. Current coverage and gaps

**Exists today** (`backend/tests`):

- `UploadDocumentTest` — upload persistence, custom name, 415/413/422/401 paths.
- `DomainSchemaTest` — canonical tables, UUID PKs, cascade delete, citation unpairing.
- Example tests (to be replaced as real behaviour lands).

**Gaps to close** (highest priority first):

1. Auth endpoints + token revocation + throttling.
2. Ownership isolation suite (`T-OWN`) for all endpoints.
3. Document list/detail/status/retry/delete.
4. Findings/citations derivation + manual review audit.
5. Reports generation rules.
6. Scoring unit tests + Crossref fixtures.
7. `AnalyzeDocumentJob` step transitions and failure handling.
8. Inference service tests (pytest).
9. Evaluation harness.

---

## 9. Exit criteria

A change is ready when:

1. All tests relevant to the touched area pass (`php artisan test`).
2. `vendor/bin/pint --dirty` reports no changes.
3. `npm run type-check` and `npm run build` pass for frontend changes.
4. New endpoints have ownership-isolation tests returning `404` for foreign resources.
5. New/changed behaviour has at least one test covering the invariant, not just the happy path.
6. No secrets are introduced; `.env` remains untouched.
7. Any spec discrepancy encountered is reported, not silently "fixed".
