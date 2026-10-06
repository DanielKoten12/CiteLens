# Phase 07 — Hardening, Evaluation Harness & Release Readiness

> **Status:** broad plan · **Depends on:** Phases 01–06
> Canonical references: `docs/TEST_PLAN.md` (all), `docs/SECURITY.md` §11,
> `docs/PRODUCT_REQUIREMENTS.md` §9/§12, `AGENTS.md` §18/§21.

---

## 1. Objective

Close the remaining correctness/quality gaps before the backend is considered feature-complete:

1. every test ID in `docs/TEST_PLAN.md` is either covered or explicitly waived;
2. the detection-quality evaluation harness exists and produces precision/recall/F1 + confusion
   matrix + suggested-DOI metrics from a labelled dataset;
3. performance and portability guardrails are in place (pagination, N+1, batching, SQLite vs
   MySQL/Postgres portability);
4. documentation status (`docs/ARCHITECTURE.md` §13, `AGENTS.md` §14) and any contract updates
   from resolved open questions are synchronized;
5. a manual end-to-end runbook exists.

---

## 2. Scope

**In:** test closure, evaluation harness, performance checks, docs sync, runbook, security
checklist pass.

**Out:** frontend tests, inference service tests (separate plan), deployment/CI tooling.

---

## 3. Deliverables

### 3.1 Test closure

- Walk `docs/TEST_PLAN.md` §5 and mark each ID: implemented / waived (with reason). Target groups:
  T-AUTH, T-DOC, T-OWN, T-REF, T-CIT, T-FIND, T-REP, T-SCORE, T-PIPE, T-SCHEMA, plus backend-side
  T-INF contract tests.
- Add the missing ownership-isolation cases for **every** document-owned endpoint
  (T-OWN-01..09 is a release blocker).
- Add envelope assertions to every endpoint suite (single, collection + `meta`, error). A shared
  assertion helper in `tests/Support/` keeps this cheap.
- Add factory-state coverage so no test builds rows with raw `DB::table()` inserts unless it is a
  schema test.
- Keep `Queue::fake`/`Bus::fake` for dispatch assertions and `Http::fake` fixtures for
  Crossref/inference — tests must not require network or a worker.

### 3.2 Evaluation harness — `app/Services/Evaluation/` + console command

Purpose: measure detection quality against a labelled dataset (`docs/TEST_PLAN.md` §6) and tune
the `config/scoring.php` values.

- Command: `php artisan analysis:evaluate {dataset} {--config=} {--output=}`
  (`app/Console/Commands/EvaluateDetectionCommand.php`).
- `EvaluationRunner` feeds each labelled item through the **pure** scoring/resolution components
  (no network): recorded candidate metadata per item, or `Http::fake`-style fixtures for a
  pipeline simulation.
- `DetectionMetrics` computes:
  - confusion matrix (TP/TN/FP/FN) for reference validity and for citation consistency,
  - `Precision = TP/(TP+FP)`, `Recall = TP/(TP+FN)`, `F1 = 2PR/(P+R)`,
  - suggested-DOI precision/recall (case 2) against the true DOI,
  - the full `config/scoring.php` snapshot used for the run (reproducibility, OQ-12).
- `EvaluationReport` writes a human-readable summary (stdout/JSON) and is unit-tested with a tiny
  hand-checked dataset.
- Dataset format (documented in this phase): JSON array of items with
  `type` (`reference` | `citation`), input fields (raw text/DOI/authors/year/marker), labelled
  expectation (`valid`/`invalid`/`not_found`/`hallucination`/pair id), and optional candidate
  metadata. Keep a small non-secret sample under `tests/Fixtures/evaluation/`.
- The harness is a research tool, not a release gate; the release gate is the correctness test
  suite.

### 3.3 Performance and portability guardrails

- Assert pagination limits (`per_page` default 15, max 100) and `422` on invalid filters across
  all list endpoints.
- Add query-count assertions (or `DB::listen` counters) for:
  - `GET /documents` (batched summaries, no N+1),
  - reference/citation/finding list endpoints (eager loading),
  - `GET /documents/{document}/status` (single cheap query).
- Verify embeddings are batched at the configured size and that no unbounded array is built for
  very large documents.
- Portability: run migrations and the full suite on SQLite (current); review raw SQL/`unionAll`
  usage for MySQL/Postgres compatibility and note any required change in the detailed plan. Do not
  silently switch the dev engine.
- Optional smoke: upload a large synthetic PDF, confirm `202` is returned without waiting for the
  pipeline (T-PIPE-05).

### 3.4 Documentation sync

- Update `docs/ARCHITECTURE.md` §13 (implementation status) and `AGENTS.md` §14 (current status)
  to reflect the completed backend.
- For each resolved OQ, apply the corresponding contract/doc change in the same work:
  - `docs/API_SPEC.md` if an endpoint shape/filter/enum changes (e.g. OQ-09 feed type list,
    OQ-02 DOI classification note, OQ-10 summary representation, OQ-17 manual-review state),
  - `docs/DB_SCHEMA.md` if a migration changes the schema (e.g. OQ-06 FK, OQ-14 unique index).
- Update this plan folder: mark phases as done, move resolved OQs into the README decision log.

### 3.5 Manual end-to-end runbook

Document (and verify once the inference service exists):

```text
1. php artisan migrate
2. php artisan queue:work   (database queue; dedicated analysis queue if configured)
3. start the inference service (uv run uvicorn …)
4. start Gotenberg, e.g. docker run --rm -p 3000:3000 gotenberg/gotenberg:8
   (private network in production; GOTENBERG_URL points at it)
5. POST /api/v1/documents with a sample PDF
6. poll GET /api/v1/documents/{id}/status
7. inspect references/citations/findings; exercise manual review + pairing
8. POST /documents/{id}/reports, poll the report, then download the PDF
9. delete the document/report and confirm storage cleanup
```

The repo has no Docker/CI configuration today, so the Gotenberg command above is the documented
local way to run it; production keeps it private on the worker network. Update `docs/SECURITY.md`
§7 to name Gotenberg alongside inference when the phase lands.

### 3.6 Security checklist (docs/SECURITY.md §11)

- All endpoints authenticate except register/login.
- Every document-owned query scoped to the caller; foreign access `404`.
- Upload/validation/enum/ownership rules covered; canonical envelopes.
- Rate limits verified for all four scopes.
- No secrets in repo/`.env.example`; `.env` untouched and ignored.
- Inference and Gotenberg stay internal; no public routes; the frontend never calls them.
- Extracted text treated as data (no raw SQL, Blade escapes).
- No stack traces/paths in responses; deletions cascade and remove files.
- Manual-review audit fields always set.

---

## 4. Contracts & invariants

- The correctness suite is the release gate; the evaluation harness tunes thresholds only.
- Every waived test ID needs a written reason (spec discrepancy, out of scope, external service).
- Contract changes discovered during hardening follow the same update-both-specs rule as every
  other phase.

---

## 5. Tests / acceptance criteria

| Area | Expected |
|---|---|
| Full Pest suite | green (`php artisan test`) |
| Ownership isolation | every endpoint returns `404` for foreign resources |
| Envelopes | every endpoint matches `docs/API_SPEC.md` §2.4/§2.5 |
| Evaluation harness | metrics computed and unit-tested on a hand-checked sample |
| Query counts | list/detail endpoints free of N+1 |
| Rate limits | all four scopes return `429 RATE_LIMITED` |
| Portability | suite green on SQLite; SQL reviewed for MySQL/Postgres |
| Docs | ARCHITECTURE/AGENTS status updated; resolved OQs reflected in canonical specs |
| Security | `docs/SECURITY.md` §11 checklist passes |
| Formatting | `vendor/bin/pint --dirty` clean |

---

## 6. Decisions (resolved)

All Phase 01–06 decisions are resolved in the README `Decision log` (2026-10-06) and reflected in
the phase documents. Phase 07 additionally confirms:

- the evaluation dataset location/ownership (research asset, possibly outside the repo),
- whether a MySQL/Postgres smoke run is expected in this environment (no CI exists today),
- the local/deployment story for Gotenberg (container command in the runbook; private network in
  production).

---

## 7. Risks

- Without an implemented inference service, end-to-end verification is limited to fakes; the
  runbook step 3 stays blocked (OQ-13 resolved: separate plan).
- Report end-to-end verification needs Gotenberg running; without it the report path is exercised
  with a faked `ReportRenderer`/`GotenbergClient` binding.
- Threshold tuning can change many test fixtures; keep fixtures parameterized by config and assert
  relative behaviour (ordering/status boundaries) rather than brittle exact scores where possible.
- Test-suite runtime grows with the full matrix; keep external calls faked and use
  `RefreshDatabase` selectively where schema tests allow.
