# Inference Implementation Plan — Dafpus Cek

> **Status:** broad plan (v1) — Phase 01 implemented and tested on PR #17 (`Grobid-1`);
> Phase 02 (SBERT) pending.
> Detailed, task-level plans are kept per phase (see §7). Each phase document is structured so it
> can be expanded into an executable plan without re-deriving a contract, invariant or acceptance
> criterion.

This folder plans the **FastAPI inference service only** (`inference/`). It is the counterpart of
[`docs/plans/backend/`](../backend/README.md): the backend plans treat the inference service as an
external contract to fake (backend OQ-13, `docs/API_SPEC.md` §9); this folder implements that
contract. The service is **internal / private-network only** and must never be exposed through a
public route or called by the frontend (`AGENTS.md` §3, `docs/SECURITY.md`).

---

## 1. How to use this plan

1. Read this file first — it defines scope, layout, cross-cutting rules, phase order and the
   decision log.
2. Read the phase document you are about to implement.
3. Expand it into a detailed plan with the Python task template:

```text
[ ] Confirm the `docs/API_SPEC.md` §9 contract section the task implements
[ ] Settings/config: env var names + `.env.example` placeholders (never real secrets)
[ ] Capability code under `inference/<capability>/` with explicit exceptions and timeouts
[ ] Pydantic schemas + `response_model` on the route
[ ] Route under `inference/api/` + error-envelope coverage
[ ] Tests: happy path · validation 413/415/422 · 422/503 failure modes · envelope shape
[ ] `uv run pytest -q` green; `uv lock --check` green
[ ] Update `docs/ARCHITECTURE.md` §13, `AGENTS.md` §14 and `docs/TEST_PLAN.md` status
```

4. This plan is a living document. When a decision changes, update the phase document and the
   decision log below instead of leaving ambiguity in the code.

### Decision log

| ID | Question | Decision | Date | Decided by |
|---|---|---|---|---|
| D-INF-01 | Where does the service app live once GROBID is ported? | One `inference` package: `src/inference/` with capability subpackages (`inference/grobid/`, later `inference/sbert/`) and `inference/api/` routers. Delete the root `inference/main.py`; canonical entry point `uvicorn inference.main:app`. `uv build` was verified to ship only `src/inference/`, so top-level `src/grobid` + `src/sbert` would be silently excluded from the wheel. | 2026-10-15 | user (PR #17 review) |
| D-INF-02 | Non-PDF / oversize input codes | Keep service behavior: `415` wrong media type/extension, `413` over the limit, `422` invalid PDF bytes, `422` unparseable/empty TEI. Correct `docs/TEST_PLAN.md` T-INF-03 (currently says non-PDF → `422`) and document `413`/`415` in `docs/API_SPEC.md` §9 + `API_SPEC_ID.md` §9. | 2026-10-15 | user (PR #17 review) |
| D-INF-03 | `/health` `sbert` field (PR TODO 4) | This phase returns `{status, grobid}` only; the health test asserts those keys and tolerates an optional future `sbert`. TODO 4 stays open; the SBERT phase fills it and updates the test. | 2026-10-15 | user (PR #17 review) |
| D-INF-04 | Fate of the `grobid 1.0 .py` prototype | Keep it under `inference/scripts/grobid_exploration.py` (valid module name) as a standalone dev script: not imported by the service, not packaged, not tested. Migrate its HTTP calls to sync `httpx` so it runs with project dependencies and no `requests` dependency is added. | 2026-10-15 | user (PR #17 review) |
| D-INF-05 | Testability refactor | App factory (`create_app()`) + frozen `Settings` + injectable `GrobidClient` (`httpx.MockTransport` in tests). No monkeypatching of module constants, no `respx`. `pytest` as a dev dependency. | 2026-10-15 | user (PR #17 review) |
| D-INF-06 | Live-GROBID coverage | Add one opt-in integration test (`-m integration`, requires `GROBID_INTEGRATION_URL` + a real PDF path) that is skipped by default; mocked `MockTransport` drives the unit/contract tests. | 2026-10-15 | user (PR #17 review) |
| D-INF-07 | Python floor | Keep `requires-python = ">=3.14"` and `.python-version = 3.14`; revisit before deployment if contributors/deployment targets need less. | 2026-10-15 | user (PR #17 review) |
| D-INF-08 | Error envelope on framework 404 | Register the HTTP-exception handler on `starlette.exceptions.HTTPException` (not the FastAPI subclass). Starlette looks handlers up by the exception's MRO, so the FastAPI-keyed handler never catches Starlette's own 404 and the envelope is bypassed. | 2026-10-15 | agent (verified) |
| D-INF-09 | Unhandled errors | Add a catch-all `Exception` handler returning the `500 SERVER_ERROR` envelope; never leak stack traces or paths. | 2026-10-15 | agent (recommended) |
| D-INF-10 | Captured real response | Validate the committed `docs/inference-extract-response.json` against the service response models in a test, so the captured sample and the contract cannot drift apart. | 2026-10-15 | agent (recommended) |
| D-INF-11 | `[project.scripts]` | Drop the stub `inference = "inference:main"` script; the documented way to run the service is `uv run uvicorn inference.main:app`. | 2026-10-15 | agent (recommended) |

---

## 2. Source of truth

When anything conflicts, the order in `AGENTS.md` §2 applies:

1. `docs/API_SPEC.md` — canonical contract (§9 for this service; English wins over
   `docs/API_SPEC_ID.md`).
2. `docs/DB_SCHEMA.md` — not touched by this service (it owns no tables).
3. The proposal (`docs/Proposal Capstone Project Kelompok 7.pdf`) — product intent; PDF-only for
   v1, no Google Scholar.
4. Existing implementation — authoritative for current behavior, never a reason to redefine a
   documented contract.

Supporting docs (`docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/TEST_PLAN.md`) explain the specs
but never override them. Any change to the `/health`, `/v1/extract` or future `/v1/embeddings`
shape updates `docs/API_SPEC.md` (and `API_SPEC_ID.md`) in the same change.

---

## 3. Baseline before Phase 01 (verified in the repo, 2026-10-15)

- `inference/` is a uv-managed Python 3.14 project (`uv 0.12.23`, `uv_build` 0.12.x) whose app is
  a hello-world stub: `inference/main.py`, `src/inference/__init__.py`, empty `README.md`,
  `[project.scripts] inference = "inference:main"`.
- `uv.lock` already pins `fastapi 0.141.1` (via `fastapi[standard]`), `starlette 1.7.0`,
  `httpx 0.28.1`, `pydantic 2.13.5`, `python-multipart 0.0.32`, `python-dotenv 1.2.3`,
  `uvicorn 0.53.0`; `pytest` is **not** installed.
- Draft PR #17 (`Grobid-1`) carries a working GROBID slice in a separate `grobid/` package
  (`grobid/app/{main,config,schemas}.py`, `grobid/app/services/{grobid,tei_parser}.py`, a
  `requirements.txt`, a 368-byte `artikel.pdf` placeholder, the `grobid 1.0 .py` exploration
  script). It is **not** wired into the uv-managed project and has no tests.
- `docs/API_SPEC.md` §9 was updated by the PR to document the full `/v1/extract` payload
  (`data.header`, per-reference `reference_index`, location `coordinate_system` and
  `location_index`); `docs/inference-extract-response.json` is a captured real response
  (14 references, 31 citations, all with locations). The Laravel `Data/Inference/*` DTOs still
  model only what the pipeline consumes and ignore the extra fields.
- Laravel's `InferenceClient` maps any 4xx to `ExtractionFailedException::invalidPdf()` and
  5xx/429 to `InferenceUnavailableException`, so the service's `413`/`415`/`422`/`503` split is
  transport-sane for the consumer.
- `docs/TEST_PLAN.md` §5.10 defines T-INF-01..07; T-INF-01/02/04 are in scope now, T-INF-03 needs
  its expectation corrected (D-INF-02), T-INF-05..07 belong to the SBERT phase.

### 3.1 What was missing (the work these plans cover)

- The GROBID slice ported into `src/inference/`, wired into the app, with uv-declared dependencies
  and a regenerated lock.
- pytest coverage for `/health`, `/v1/extract`, validation (`413`/`415`/`422`) and the error
  envelope, plus parser unit tests.
- The `/v1/embeddings` SBERT endpoint (later phase).
- Container/deployment tooling (not in this repo yet; intentionally out of scope).

### 3.2 Phase 01 outcome (2026-10-15)

- `inference/` is one package: `src/inference/{main,config,schemas}.py`,
  `src/inference/api/{health,extract}.py`, `src/inference/grobid/{client,tei}.py`; entry point
  `uvicorn inference.main:app`; dependencies and lock are uv-declared (`httpx`,
  `python-multipart`; dev group `pytest`, `httpx2`).
- `GET /health` and `POST /v1/extract` are implemented with typed schemas, the standard error
  envelope (`413`/`415`/`422`/`503`) and a hermetic pytest suite (`uv run pytest`: 36 passed,
  1 opt-in integration skip).
- `docs/API_SPEC.md`/`API_SPEC_ID.md` §9 document the `413`/`415` failure codes; T-INF-03 in
  `docs/TEST_PLAN.md` is corrected; `AGENTS.md` and `docs/ARCHITECTURE.md` reflect the new layout.
- Remaining for Phase 02: `inference/sbert/`, `POST /v1/embeddings`, `/health.sbert` (PR TODO 4).

---

## 4. Scope

### 4.1 In scope

- The FastAPI service in `inference/` and its internal contract (`docs/API_SPEC.md` §9).
- PDF extraction orchestration through GROBID, TEI parsing, coordinates and offsets.
- Semantic embeddings through SBERT (`/v1/embeddings`), when the SBERT phase lands.
- Service tests (pytest) per `docs/TEST_PLAN.md` §5.10.
- Keeping `docs/ARCHITECTURE.md` §13 / `AGENTS.md` §14 status current as phases land.

### 4.2 Out of scope

- Laravel orchestration, persistence, scoring, Crossref, citation resolution, reports — the
  backend owns those (`docs/plans/backend/`). The service never decides validity.
- Auth on the inference service (private network only in v1; `docs/API_SPEC.md` §9).
- Public routing, frontend access, DOCX, OpenAlex, Google Scholar.
- CI/CD or container tooling (none exists in the repo; adding it is a deliberate separate change).

### 4.3 External dependencies

| Dependency | Contract | Owner |
|---|---|---|
| GROBID (Docker service) | `POST /api/processFulltextDocument`, `GET /api/isalive`; `teiCoordinates` repeated form fields | Operations; called by this service |
| SBERT model (later) | Local inference in this process; writes no external calls | This service |

---

## 5. Target shape

```text
inference/
├── .env.example                     # GROBID_URL / GROBID_TIMEOUT_SECONDS / MAX_UPLOAD_MB
├── .gitignore
├── README.md                        # uv setup, run, endpoints, errors, tests
├── pyproject.toml                   # deps + dev group + pytest config
├── uv.lock
├── .python-version                  # 3.14
├── scripts/
│   └── grobid_exploration.py        # standalone dev script (D-INF-04), not packaged
├── src/inference/
│   ├── __init__.py
│   ├── main.py                      # create_app(), exception handlers, router wiring, app
│   ├── config.py                    # Settings + get_settings()
│   ├── schemas.py                   # contract models (responses + error envelope)
│   ├── api/                         # HTTP edge: one router per contract group
│   │   ├── health.py                # GET /health
│   │   └── extract.py               # POST /v1/extract
│   ├── grobid/                      # GROBID capability
│   │   ├── client.py                # GrobidClient, errors, get_grobid_client()
│   │   └── tei.py                   # parse_tei + TEI helpers
│   └── sbert/                       # (phase 02) SBERT capability
└── tests/
    ├── conftest.py
    ├── fixtures/{documents,tei}/
    └── test_*.py
```

**Why capability subpackages under one `inference` package** (D-INF-01): the service is one
deployable process and one distribution, so it gets one import root. `src/grobid` + `src/sbert`
as top-level packages would (a) silently drop out of the wheel — verified with a `uv build`
experiment, only `src/inference/` is packaged — and (b) expose generic import names
(`grobid`, `sbert`) that collide with other distributions. Capability subpackages keep each
engine's client, parser, errors and settings together; `api/` stays a thin HTTP edge that
composes capabilities; `main.py` only builds the app and wires handlers.

---

## 6. Cross-cutting rules

- **Internal only.** No public route, no frontend access, no auth in v1 (`docs/API_SPEC.md` §9,
  `docs/SECURITY.md`). Laravel is the only caller.
- **Contract first.** `/health`, `/v1/extract` and future `/v1/embeddings` shapes come from
  `docs/API_SPEC.md` §9. Changed shape → spec change in the same work.
- **Error envelope.** Every non-2xx: `{"error": {"code", "message", "details"?}}`. Validation
  errors → `422 VALIDATION_ERROR`; unparseable input → `422 EXTRACTION_FAILED`; GROBID
  unreachable → `503 EXTRACTION_FAILED`. No stack traces, SQL, paths or internal exception text
  in responses.
- **Validation at the boundary.** PDF-only, 20 MB default cap; the cap is configuration, never a
  literal. Request size is read with a bound (`max_upload_bytes + 1`), never unbounded.
- **Configuration is environment-driven** with `.env.example` placeholders only; no secrets ever
  committed. `.env` stays git-ignored.
- **Typed schemas.** Pydantic models are the single source for response shapes and are attached as
  `response_model`, so the service cannot silently drift from the documented payload.
- **Explicit failure types.** Capability errors (`GrobidUnavailableError`, `GrobidExtractionError`,
  later SBERT-unavailable) are distinct; routes map them to the documented status codes in one
  place.
- **Timeouts on every outbound call.** No unbounded httpx call.
- **Hermetic tests.** No network, no real GROBID in the default suite; `httpx.MockTransport`
  against the real client. One opt-in integration test for real GROBID.
- **No dependency without justification.** Each new direct dependency is declared in
  `pyproject.toml` and justified in the phase detail.

---

## 7. Phase map

| Phase | Document | Objective | Depends on |
|---|---|---|---|
| 01 | [`01-grobid-extraction-service.md`](01-grobid-extraction-service.md) → [`01-grobid-extraction-service-detail.md`](01-grobid-extraction-service-detail.md) | Port the GROBID slice into `inference/`, declare dependencies with uv, add the health/extract pytest matrix (PR #17 TODOs 1–3) | — |
| 02 | *(to be written)* | SBERT capability + `POST /v1/embeddings` (batching, `422`/`503`), `/health` gains `sbert` (PR #17 TODO 4) | 01 |
| 03 | *(to be written)* | Deployment/hardening: container image, resource limits, model warm-up, runbook | 01, 02 |

```text
01 ──▶ 02 ──▶ 03
```

Phase 01 is blocking: it fixes the package layout, the config/dependency seam and the test harness
that phase 02 extends without moving anything again.

---

## 8. Traceability

### 8.1 Endpoint → phase

| Endpoint (`docs/API_SPEC.md` §9) | Phase | Status |
|---|---|---|
| `GET /health` | 01 (`{status, grobid}`), 02 (`sbert`) | to implement |
| `POST /v1/extract` | 01 | to implement |
| `POST /v1/embeddings` | 02 | not implemented |

### 8.2 Test IDs → phase

| Test group (`docs/TEST_PLAN.md` §5.10) | Phase |
|---|---|
| T-INF-01 (`GET /health`), T-INF-02 (`/v1/extract` valid PDF), T-INF-04 (`503`) | 01 |
| T-INF-03 (input validation, expectation corrected by D-INF-02) | 01 |
| T-INF-05..07 (`/v1/embeddings`) | 02 |

### 8.3 PR #17 TODO → phase

| PR #17 TODO | Phase |
|---|---|
| 1. Move `grobid/` into `inference/`, wire the app, rename the script | 01 |
| 2. Replace `requirements.txt` with uv + lock | 01 |
| 3. Tests for `/health`, `/v1/extract`, `413`/`415`/`422`, error envelope | 01 |
| 4. `/health` `sbert` (add or amend spec) | 02 (D-INF-03) |
| 5. Replace the `artikel.pdf` placeholder with proper fixtures | partially 01 (test fixture), finished in 02 |

---

## 9. Definition of done (per phase)

1. Every endpoint matches `docs/API_SPEC.md` §9 (status, envelope, field names, nullability).
2. Happy path + validation + failure modes covered by pytest (`docs/TEST_PLAN.md` §5.10).
3. `uv sync --locked` and `uv run pytest -q` pass on a clean checkout.
4. `uv lock --check` passes; `pyproject.toml` is the only dependency source.
5. No secrets committed; `.env.example` only gains placeholders.
6. Any contract discrepancy is reported and fixed deliberately (spec + tests in the same change).
7. `docs/ARCHITECTURE.md` §13 and `AGENTS.md` §14 describe the new reality.

---

## 10. Configuration and environment additions

| Setting | Env var | Default | Phase | Purpose |
|---|---|---|---|---|
| `settings.grobid_url` | `GROBID_URL` | `http://localhost:8070` | 01 | GROBID base URL (private network) |
| `settings.grobid_timeout_seconds` | `GROBID_TIMEOUT_SECONDS` | `300` | 01 | Extract read timeout (s) |
| `settings.grobid_health_timeout_seconds` | `GROBID_HEALTH_TIMEOUT_SECONDS` | `5` | 01 | `/api/isalive` timeout (s) |
| `settings.max_upload_mb` | `MAX_UPLOAD_MB` | `20` | 01 | Upload cap, mirrors the public API cap |
| `settings.sbert_model` | `SBERT_MODEL` | *(phase 02)* | 02 | Embedding model name |
| `settings.embedding_batch_size` | `EMBEDDING_BATCH_SIZE` | `32` | 02 | `/v1/embeddings` chunking (spec note) |

---

## 11. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Handcrafted TEI fixtures miss real GROBID variants | Parser accepts only what the fixture encodes | Opt-in integration test against real GROBID; captured real response as contract sample (D-INF-10) |
| GROBID output changes between versions | `coords`, `target`, `xml:id` handling breaks silently | Keep parsing defensive (invalid coords skipped, missing fields `null`); integration test documents the GROBID version |
| Moving files breaks the Laravel contract unnoticed | Extraction fails in the pipeline | Wire shape unchanged; backend `InferenceClientTest` / `InferenceDataTest` stay green; captured sample test |
| SBERT dependencies (torch) bloat phase 01 | Slow builds, large images | SBERT is phase 02; phase 01 declares no model dependency |
| No CI in the repo | Tests can rot silently | Document the command in `inference/README.md` + `docs/TEST_PLAN.md` §3.1; add CI deliberately later |
| Python 3.14 requirement limits contributor machines | `uv sync` fails for some contributors | D-INF-07 (keep for now); revisit before deployment |
