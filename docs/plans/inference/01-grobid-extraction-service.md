# Phase 01 — GROBID Extraction Service (Internal Inference API)

> **Status:** broad plan — executable detail in
> [`01-grobid-extraction-service-detail.md`](01-grobid-extraction-service-detail.md) · **Depends on:** — ·
> **Unblocks:** Phase 02 (SBERT)
> Canonical references: `docs/API_SPEC.md` §9 (payload + failure codes), `docs/ARCHITECTURE.md`
> §3/§4/§10/§13, `docs/SECURITY.md` §3/§8, `docs/TEST_PLAN.md` §3.1/§5.10 (T-INF), `AGENTS.md`
> §3/§14/§16/§17.
> Parent: [`README.md`](README.md). PR: #17 (`Grobid-1`), TODOs 1–3.

This document scopes the first phase of the inference service: port the draft-PR GROBID slice into
the uv-managed `inference/` project, declare its dependencies with uv, and prove the internal
contract (`GET /health`, `POST /v1/extract`) with pytest. It deliberately changes **no**
`docs/API_SPEC.md` payload shape and **no** Laravel code — the backend already consumes this
contract through `InferenceClient` and its fakes.

---

## 1. Objective

Ship a reviewable, tested, uv-managed internal extraction service whose `/v1/extract` response the
Laravel pipeline can consume unchanged:

- one `inference` package (`src/inference/`) with the GROBID capability as a subpackage,
- an app factory with dependency-injected settings and GROBID client,
- typed Pydantic schemas for the documented payload and the standard error envelope,
- pytest coverage for `/health`, `/v1/extract`, input validation (`413`/`415`/`422`) and the
  error envelope, hermetic by default.

---

## 2. Scope

**In:**

- Port `grobid/app/**` into `inference/src/inference/**`; delete the `grobid/` package and the
  hello-world `inference/main.py`.
- App factory + exception handlers + routers (`api/health.py`, `api/extract.py`).
- `Settings` (env-driven) and an injectable `GrobidClient` with explicit failure types.
- Typed response models used as `response_model` (`header`, references, citations, locations,
  error envelope).
- `pyproject.toml` dependencies (`httpx`, `python-multipart`), a dev dependency group (pytest),
  pytest config, regenerated `uv.lock`; delete `grobid/requirements.txt`.
- The `grobid 1.0 .py` prototype moved to `inference/scripts/grobid_exploration.py` (D-INF-04).
- Tests + fixtures (TEI, minimal PDF), the captured-response contract test (D-INF-10) and an
  opt-in live-GROBID integration test (D-INF-06).
- Docs sync: `inference/README.md`, `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/TEST_PLAN.md`,
  `docs/API_SPEC.md` §9 + `API_SPEC_ID.md` §9 for `413`/`415` (D-INF-02).

**Out:**

- SBERT / `POST /v1/embeddings` / `/health.sbert` (Phase 02, PR TODO 4).
- Scoring, validity decisions, citation resolution — Laravel owns them (`AGENTS.md` §3).
- Container/deployment tooling, CI (no such tooling exists in the repo today).
- Any public route, auth, or frontend access.

---

## 3. Deliverables

| # | Deliverable | Where |
|---|---|---|
| D1 | `inference` package layout: `main.py`, `config.py`, `schemas.py`, `api/`, `grobid/` | `inference/src/inference/` |
| D2 | `Settings` + `get_settings()` (env-driven, test-overridable) | `inference/src/inference/config.py` |
| D3 | `GrobidClient` (`is_alive`, `extract_tei`) + `GrobidUnavailableError` / `GrobidExtractionError` + `get_grobid_client()` dependency | `inference/src/inference/grobid/client.py` |
| D4 | `parse_tei` + TEI helpers (moved; `TEIParseError`) | `inference/src/inference/grobid/tei.py` |
| D5 | Typed contract models | `inference/src/inference/schemas.py` |
| D6 | `create_app()` + handlers (`starlette` `HTTPException`, `RequestValidationError`, catch-all `Exception`) + `app` | `inference/src/inference/main.py` |
| D7 | Routers `GET /health`, `POST /v1/extract` | `inference/src/inference/api/{health,extract}.py` |
| D8 | Dependencies + pytest config + lock | `inference/pyproject.toml`, `inference/uv.lock` |
| D9 | Test suite + fixtures | `inference/tests/` |
| D10 | Run/config docs + status sync | `inference/README.md`, `.env.example`, `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/TEST_PLAN.md`, `docs/API_SPEC*.md` |
| D11 | Prototype script (renamed, dev-only) | `inference/scripts/grobid_exploration.py` |

Deleted: `grobid/**`, `grobid/requirements.txt`, `inference/main.py`, `inference/.gitkeep`.

---

## 4. Design guidance

### 4.1 Package layout

- One import root (`inference`) with capability subpackages (`inference.grobid`, later
  `inference.sbert`) and `inference.api` routers. Rationale and verified build behavior:
  [`README.md`](README.md) §5 (D-INF-01).
- `main.py` is wiring only: `create_app()`, exception handlers, router registration, module-level
  `app = create_app()` for uvicorn.
- `api/` holds transport logic (validation order, status codes); capability packages hold HTTP
  clients and parsing; `schemas.py` holds the wire models.

### 4.2 Settings

- Frozen dataclass `Settings` with defaults matching `grobid/.env.example`, `from_env()` reading
  the same variable names (`GROBID_URL`, `GROBID_TIMEOUT_SECONDS`, `GROBID_HEALTH_TIMEOUT_SECONDS`,
  `MAX_UPLOAD_MB`), a `max_upload_bytes` property, and a cached `get_settings()` FastAPI
  dependency.
- No `os.getenv` at import time and no module-level constants: tests build
  `Settings(max_upload_mb=1)` and override the dependency to exercise `413` without a 20 MB upload.

### 4.3 GROBID client

- `GrobidClient(base_url, timeout_seconds, health_timeout_seconds, transport=None)`; the optional
  `httpx.AsyncBaseTransport` is the test seam (`httpx.MockTransport`).
- `is_alive()` → `GET /api/isalive`; any httpx error or non-200 → `False`.
- `extract_tei(pdf_bytes, filename)` → `POST /api/processFulltextDocument` with multipart
  `input` and **repeated** `teiCoordinates` form fields (`ref`, `biblStruct`); do not join them
  into one value.
- Failure mapping: connection/timeout/5xx → `GrobidUnavailableError`; other non-200, empty or
  non-TEI body → `GrobidExtractionError`. Explicit timeouts from settings on every call.

### 4.4 TEI parsing

- Keep the current semantics exactly: header from `teiHeader`; references from
  `text/back//listBibl/biblStruct` (with `xml:id` → payload index, `xml_id` stripped from the
  output); citations from `text/body//ref[@type='bibr']`; `coords` ("page,x,y,w,h;…") mapped to
  boxes with `coordinate_system = "pdf_points_top_left"` and 0-based `location_index`; page sizes
  from `facsimile/surface`.
- Offsets currently index the normalized full `<text>` for references and the normalized
  `<body>` for citations. Tests assert this behavior; do not change it in this phase (Laravel
  orders references by `text_start_offset` and reindexes occurrences deterministically).
- Defensive parsing: malformed coordinate entries are skipped; missing metadata stays `null`;
  unparseable XML raises `TEIParseError` (subclass of `ValueError`).

### 4.5 Schemas

- `LocationData`, `ReferenceData`, `CitationData`, `HeaderData`, `ExtractionData`,
  `ExtractResponse`, `HealthData`, `ErrorBody`, `ErrorResponse`. Used with `response_model` so
  the service validates its own payload against `docs/API_SPEC.md` §9.
- Nullability mirrors the spec: reference metadata fields optional, `locations` non-null list,
  header `authors` a list (possibly empty), location `page_width`/`page_height` optional.

### 4.6 Error handling

- One `error_response()` helper + three handlers:
  - `starlette.exceptions.HTTPException` → documented code/message mapping (fixes framework 404s,
    D-INF-08); detail may carry `{code, message, details}`,
  - `fastapi.exceptions.RequestValidationError` → `422 VALIDATION_ERROR` with `details`,
  - `Exception` → `500 SERVER_ERROR` without leaking internals (D-INF-09).
- `/v1/extract` validation order: media type/extension (`415`) → size (`413`) → PDF magic bytes
  (`422`) → GROBID availability (`503`) → unparseable TEI (`422`). Keep `docs/API_SPEC.md` §9 in
  sync for `413`/`415` (D-INF-02).

---

## 5. Contracts and invariants

- `docs/API_SPEC.md` §9 is the wire contract; this phase does not change the payload shape.
  The only spec edits are documenting the already-implemented `413`/`415` failure cases and
  correcting `docs/TEST_PLAN.md` T-INF-03.
- The service stays internal: no public route, no auth, no frontend callers, no Crossref/SBERT
  exposure (`AGENTS.md` §3).
- PDF-only, default 20 MB cap; the cap is configuration, never a literal in the route.
- Every non-2xx response uses the `{error: {code, message, details?}}` envelope.
- Coordinates keep `pdf_points_top_left`, 1-based `page_number`, 0-based `location_index`; no
  rescaling/reordering here (Laravel rounds to 4 decimals).
- The Laravel `InferenceClient` contract is unchanged: 4xx → unprocessable PDF, 5xx/429 →
  inference unavailable.
- No secrets: `.env.example` placeholders only; `.env` ignored.

---

## 6. Tests and acceptance

Default suite is hermetic (`httpx.MockTransport`); one integration test is opt-in. Full matrix in
the detail plan (§11). Phase acceptance:

- T-INF-01 (health up/down), T-INF-02 (extract + request contract + empty/parse failures),
  T-INF-04 (`503`), T-INF-03 corrected (`415` wrong media type, `413` over cap, `422` invalid PDF),
  envelope tests for `404`/`500`, parser unit tests, captured-response validation (D-INF-10).
- `uv sync --locked`, `uv lock --check` and `uv run pytest -q` pass.
- `uv run uvicorn inference.main:app` serves `/health`, `/v1/extract`, OpenAPI docs.
- Backend `InferenceClientTest` / `InferenceDataTest` stay green (contract unchanged).
- Docs status updated (`AGENTS.md` §14, `docs/ARCHITECTURE.md` §13, `docs/TEST_PLAN.md`).

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| Handcrafted TEI fixtures miss real variants | Opt-in integration test against real GROBID + captured-response contract test |
| Moving the package silently changes the wire shape | Typed `response_model` + captured-response validation + backend contract tests |
| Error envelope bypassed on framework errors | Register the handler on `starlette.exceptions.HTTPException` (D-INF-08) and test `404` |
| `uv.lock` drift | `uv lock --check` in the acceptance loop; lock committed with `pyproject.toml` |
| Phase 02 restructures again | Capability layout fixed now (`grobid/`, later `sbert/`); phase 02 only adds |

---

## 8. Open decisions

All decisions taken during PR #17 review are recorded in [`README.md`](README.md) §1 (D-INF-01..11).
No open question blocks this phase; the detail plan's §16 lists only follow-ups that belong to
Phase 02 or deployment (PR TODO 4/5, `Dockerfile`, Python floor).
