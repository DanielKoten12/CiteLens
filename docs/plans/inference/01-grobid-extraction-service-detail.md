# Phase 01 — GROBID Extraction Service (Detailed Implementation Plan)

> **Status:** implemented (2026-10-15)
> **Parent:** [`01-grobid-extraction-service.md`](01-grobid-extraction-service.md)
> **Depends on:** — · **Unblocks:** Phase 02 (SBERT)
> Canonical references: `docs/API_SPEC.md` §9, `docs/ARCHITECTURE.md` §3/§4/§10/§13,
> `docs/SECURITY.md` §3/§8, `docs/TEST_PLAN.md` §3.1/§5.10, `AGENTS.md` §3/§14/§16/§17.
> Task template: [`README.md`](README.md) §1. PR: #17 (`Grobid-1`), TODOs 1–3.

This document expands the phase plan into executable tasks. It ports the draft-PR GROBID slice into
the uv-managed `inference` package, declares its dependencies with uv, and adds the pytest matrix
for the internal contract. It makes **no** payload-shape change (`docs/API_SPEC.md` §9 stays the
contract) and no Laravel change; the only spec edits are the `413`/`415` failure codes already
implemented by the service and the `docs/TEST_PLAN.md` T-INF-03 correction (D-INF-02).

Every task is independently reviewable and leaves the repo green (`uv lock --check` +
`uv run pytest -q`). Phase 02 adds `inference/sbert/` and `api/embeddings.py` without moving any
file this phase creates (§16).

---

## 1. Baseline (verified against the repository, 2026-10-15)

| Item | Value |
|---|---|
| Branch / PR | `Grobid-1` @ `d179ea6`, draft PR #17, `main` merged in; diff vs `main` is `grobid/` + docs/DTO docblocks |
| Python / uv | Python `3.14.8` (`.python-version` = `3.14`), uv `0.12.23`, build backend `uv_build 0.12.x` |
| Installed (resolved via `fastapi[standard]`) | `fastapi 0.141.1`, `starlette 1.7.0`, `httpx 0.28.1`, `pydantic 2.13.5`, `python-multipart 0.0.32`, `python-dotenv 1.2.3`, `uvicorn 0.53.0`, `anyio 4.15.1`; **pytest absent** |
| `inference/` today | `main.py` hello world; `src/inference/__init__.py` prints; empty `README.md`; `[project.scripts] inference = "inference:main"`; `.gitkeep` |
| `grobid/` today | `app/main.py` (routes + handlers + validation), `app/config.py` (`os.getenv` constants), `app/schemas.py`, `app/services/grobid.py` (`check_grobid`, `extract_tei`), `app/services/tei_parser.py` (`parse_tei`), `.env.example`, `.gitignore`, `README.md`, `requirements.txt`, `artikel.pdf` (368 B, valid PDF), `grobid 1.0 .py` (982-line prototype, `requests`) |
| Contract docs | `docs/API_SPEC.md` §9 updated by the PR: `data.header`, reference `reference_index`, location `coordinate_system`/`location_index`; `docs/inference-extract-response.json` is a captured real response (14 refs / 31 citations, all with locations) |
| Laravel consumer | `Services/Inference/InferenceClient` ignores unmodeled payload fields; any 4xx → `ExtractionFailedException::invalidPdf()`, 5xx/429 → `InferenceUnavailableException`; `Data/Inference/*` DTOs model only what the pipeline consumes. Tests: `tests/Feature/InferenceClientTest.php`, `InferenceDataTest.php`, fixtures `tests/Fixtures/inference/*` |
| Test plan | `docs/TEST_PLAN.md` §3.1 documents `uv run pytest`; T-INF-01/02/04 in scope, T-INF-03 must change to `415`/`413`/`422` (D-INF-02), T-INF-05..07 = Phase 02 |

Facts that constrain the implementation:

- `uv build` packages only `src/inference/`: a wheel built with extra `src/grobid` / `src/sbert`
  packages contains just `inference/`. Top-level capability packages are therefore out (D-INF-01).
- Starlette resolves exception handlers by `type(exc).__mro__`; `fastapi.exceptions.HTTPException`
  subclasses `starlette.exceptions.HTTPException`, so a handler keyed on the FastAPI class never
  catches Starlette's own 404 (D-INF-08). The handler must be keyed on `starlette.exceptions.HTTPException`.
- The parser's offset semantics differ by kind: references are located in the normalized full
  `<text>` (body + back), citations in the normalized `<body>`. Laravel only compares offsets
  within each kind; this phase must not change the behavior, only pin it with tests.
- `httpx` multipart with `data={"teiCoordinates": ["ref", "biblStruct"]}` sends the field twice,
  which is what GROBID expects; joining the values into one string silently disables coordinates.
- The 368-byte `artikel.pdf` is a valid PDF but a poor real-GROBID sample; it is acceptable for
  mocked flows and becomes `tests/fixtures/documents/minimal.pdf`, while the opt-in integration
  test requires a real paper via env (PR TODO 5 finishes fixture curation in Phase 02).

---

## 2. Execution model

### 2.1 Task order, dependencies and suggested commits

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Package move + entry point + uv declarations + lock (PR TODOs 1 and 2 land together — a move that cannot `uv sync` is not green) | L | T0 | `feat(inference): port GROBID extraction service into inference/` |
| **T2** | `Settings` + typed schemas | M | T1 | `refactor(inference): add settings and typed contract schemas` |
| **T3** | Injectable `GrobidClient` + explicit errors | M | T2 | `refactor(inference): wrap GROBID in an injectable client` |
| **T4** | App factory, routers, handlers | L | T2, T3 | `refactor(inference): build the app from a factory with routers` |
| **T5** | Tests, fixtures, harness (T-INF matrix) | L | T4 | `test(inference): cover health, extract, validation and error envelopes` |
| **T6** | Prototype script archived under `scripts/` | S | T1 | `chore(inference): archive the GROBID exploration script` |
| **T7** | Docs sync + final validation | S | T5, T6 | `docs(inference): sync service status and contract failure codes` |

T2–T4 are a sequence (settings/schemas → client → app); T6 is independent and can land any time.
T5 must land with or before T7 so the docs describe tested behavior.

### 2.2 Per-task loop

1. Re-read the `docs/API_SPEC.md` §9 section and the decision(s) the task cites.
2. Implement the task and its tests in the **same** change (except T1–T4, whose refactor commits
   keep behavior and are covered by T5's matrix in the same PR).
3. `cd inference && uv run pytest tests/<affected> -q` — affected tests only.
4. `uv run pytest -q` and `uv lock --check` — full suite + lock consistency.
5. For route/config changes: `uv run uvicorn inference.main:app` smoke (curl `/health`,
   `/v1/extract`, open `/docs`).
6. Only T7 touches status docs; record discrepancies instead of silently fixing contracts.

### 2.3 Exit criteria

- `uv sync --locked` and `uv run pytest -q` pass from a clean checkout; `uv lock --check` passes.
- `grobid/` and `requirements.txt` are gone; no `from app.` / `import app.` remnants.
- `uv run uvicorn inference.main:app` serves `/health`, `/v1/extract` and the OpenAPI docs.
- T-INF-01/02/03/04 pass with the corrected expectations; `404`/`500` envelopes covered.
- The wire payload the tests assert equals `docs/API_SPEC.md` §9; the captured
  `docs/inference-extract-response.json` validates against the response models.
- Backend `vendor/bin/pest tests/Feature/InferenceClientTest.php tests/Feature/InferenceDataTest.php`
  stays green (no contract change).
- `AGENTS.md` §14/§16, `docs/ARCHITECTURE.md` §4/§13, `docs/TEST_PLAN.md` T-INF and the inference
  plan status reflect the new reality.

---

## 3. Conventions this phase locks in

- **One import root.** `inference/src/inference/` with capability subpackages (`inference.grobid`,
  later `inference.sbert`) and `inference.api` routers; no top-level `src/grobid` (verified build
  exclusion, D-INF-01).
- **Wire models in one place.** `schemas.py` is the only source of response shapes; routes attach
  them as `response_model`; capability code returns plain data.
- **Dependencies are constructor arguments.** Settings and the GROBID client are FastAPI
  dependencies; tests override them. No `os.getenv` at import time, no monkeypatching.
- **Explicit failure types.** `GrobidUnavailableError` (transient) vs `GrobidExtractionError`
  (bad input/response) vs `TEIParseError`. Routes translate them once.
- **Error envelope everywhere.** `{error: {code, message, details?}}`, Indonesian messages where
  `docs/API_SPEC.md` gives an example.
- **Hermetic by default.** `httpx.MockTransport` + `TestClient`; only the `integration`-marked test
  talks to a real GROBID and it skips unless explicitly configured.
- **Miniature, reviewable tests.** One fixture per concern; no snapshot goldens; assert exact
  statuses, codes and the fields the contract names.

---

## 4. Decision log additions

The decisions that shape this phase live in [`README.md`](README.md) §1: D-INF-01 (layout),
D-INF-02 (`413`/`415`), D-INF-03 (`sbert` deferred), D-INF-04 (script archived), D-INF-05
(factory/injection), D-INF-06 (integration opt-in), D-INF-07 (Python 3.14), D-INF-08 (Starlette
handler), D-INF-09 (catch-all 500), D-INF-10 (captured sample test), D-INF-11 (drop
`[project.scripts]`).

---

## 5. T0 — Preflight & baseline

1. `git fetch && git status` — confirm the branch is `Grobid-1` and current with `main`; do not
   touch unrelated `.idea/` / `.DS_Store` working-tree noise.
2. `cd inference && uv sync` — confirm the stub environment resolves before moving files.
3. `rg -n "from app\.|import app\." grobid inference` — record every import that must be
   rewritten.
4. `rg -n "grobid|inference/main|uvicorn main" AGENTS.md docs/ARCHITECTURE.md docs/TEST_PLAN.md`
   — record every doc line that must change in T7.
5. `uv run python -c "import main"` — confirm the hello-world module is the only app entry today
   (it will be deleted in T1).

No commit for T0.

---

## 6. T1 — Package move, entry point and uv dependencies (PR TODOs 1–2)

### 6.1 File mapping

Use `git mv` (history-preserving) exactly as below:

| From | To |
|---|---|
| `grobid/app/main.py` | `inference/src/inference/main.py` |
| `grobid/app/config.py` | `inference/src/inference/config.py` |
| `grobid/app/schemas.py` | `inference/src/inference/schemas.py` |
| `grobid/app/services/grobid.py` | `inference/src/inference/grobid/client.py` |
| `grobid/app/services/tei_parser.py` | `inference/src/inference/grobid/tei.py` |
| `grobid/.env.example` | `inference/.env.example` |
| `grobid/.gitignore` | `inference/.gitignore` |
| `grobid/README.md` | folded into `inference/README.md` (rewritten in T7), then deleted |
| `grobid/grobid 1.0 .py` | moved in T6 to `inference/scripts/grobid_exploration.py` |
| `grobid/requirements.txt` | deleted |
| `grobid/artikel.pdf` | moved to `inference/tests/fixtures/documents/minimal.pdf` (T5; can be done here) |
| `inference/main.py`, `inference/.gitkeep` | deleted |

Create `inference/src/inference/grobid/__init__.py` and `inference/src/inference/api/__init__.py`.

### 6.2 Import rewrite

- `from app.config import X` → `from inference.config import X`
- `from app.services.grobid import …` → `from inference.grobid.client import …`
- `from app.services.tei_parser import parse_tei` → `from inference.grobid.tei import parse_tei`

No behavior change in T1; `main.py` still holds routes/handlers until T4.

### 6.3 `pyproject.toml`

```toml
[project]
name = "inference"
version = "0.1.0"
description = "Internal Dafpus Cek inference service (GROBID extraction, SBERT embeddings)"
readme = "README.md"
authors = [{ name = "Bagus Nur Andiansyah", email = "bagusnur@protonmail.com" }]
requires-python = ">=3.14"
dependencies = [
    "fastapi[standard]>=0.141.1",
    "httpx>=0.28.1",
    "python-multipart>=0.0.32",
]

[dependency-groups]
dev = ["pytest>=8.4"]

[build-system]
requires = ["uv_build>=0.12.18,<0.13.0"]
build-backend = "uv_build"

[tool.pytest.ini_options]
testpaths = ["tests"]
markers = ["integration: requires a live GROBID instance (opt-in)"]
```

- `httpx` and `python-multipart` are direct imports/requirements (`GrobidClient`, `File(...)`);
  they were already resolved transitively, so the lock only records the direct edges.
- `[project.scripts]` is removed (D-INF-11); the canonical run command is
  `uv run uvicorn inference.main:app`.
- `pytest>=8.4` is the first version with Python 3.14 support.

Commands: `uv lock && uv sync && uv lock --check && uv run pytest -q` (no tests yet → pytest
exits `5`; add `|| true` in the task loop only while T5 is absent, or defer the full-suite gate to
T5). Delete `grobid/requirements.txt`; add `.env.example` placeholders only
(`GROBID_URL`, `GROBID_TIMEOUT_SECONDS`, `GROBID_HEALTH_TIMEOUT_SECONDS`, `MAX_UPLOAD_MB`).

---

## 7. T2 — `Settings` and typed schemas

### 7.1 `src/inference/config.py`

```python
@dataclass(frozen=True, slots=True)
class Settings:
    grobid_url: str = "http://localhost:8070"
    grobid_timeout_seconds: float = 300.0
    grobid_health_timeout_seconds: float = 5.0
    max_upload_mb: int = 20

    @property
    def max_upload_bytes(self) -> int:
        return max(1, self.max_upload_mb) * 1024 * 1024

    @classmethod
    def from_env(cls) -> "Settings": ...

@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings.from_env()
```

- `from_env()` reads the five env names unchanged and strips trailing `/` from `grobid_url`.
- All fields have defaults so tests construct `Settings(max_upload_mb=1)` directly.
- `GROBID_HEALTH_TIMEOUT_SECONDS` is new (the old hard-coded `5.0` in `check_grobid`) and is added
  to `.env.example`.

### 7.2 `src/inference/schemas.py`

```python
class LocationData(BaseModel):
    page_number: int
    x: float
    y: float
    width: float
    height: float
    page_width: float | None = None
    page_height: float | None = None
    coordinate_system: Literal["pdf_points_top_left"]
    location_index: int

class HeaderData(BaseModel):
    title: str | None = None
    authors: list[str] = Field(default_factory=list)
    abstract: str | None = None
    publication_year: int | None = None
    doi: str | None = None

class ReferenceData(BaseModel):
    reference_index: int
    raw_text: str | None = None
    title: str | None = None
    authors: str | None = None
    publication_name: str | None = None
    publication_year: int | None = None
    doi: str | None = None
    text_start_offset: int | None = None
    text_end_offset: int | None = None
    locations: list[LocationData] = Field(default_factory=list)

class CitationData(BaseModel):
    citation_text: str | None = None
    citation_marker: str | None = None
    context_before: str | None = None
    context_after: str | None = None
    text_start_offset: int | None = None
    text_end_offset: int | None = None
    occurrence_index: int
    reference_index: int | None = None
    locations: list[LocationData] = Field(default_factory=list)

class ExtractionData(BaseModel):
    header: HeaderData
    references: list[ReferenceData] = Field(default_factory=list)
    citations: list[CitationData] = Field(default_factory=list)

class ExtractResponse(BaseModel):
    data: ExtractionData

class HealthData(BaseModel):
    status: Literal["ok", "degraded"]
    grobid: Literal["up", "down"]

class ErrorBody(BaseModel):
    code: str
    message: str
    details: dict[str, Any] | None = None

class ErrorResponse(BaseModel):
    error: ErrorBody
```

- Field names match the parser output 1:1 (snake_case on the wire).
- `HealthData` intentionally has no `sbert` this phase (D-INF-03).
- Pydantic ignores unknown extras, so the captured real response validates even if the service
  later adds fields.

---

## 8. T3 — Injectable `GrobidClient`

### 8.1 `src/inference/grobid/client.py`

```python
class GrobidUnavailableError(RuntimeError): ...
class GrobidExtractionError(RuntimeError): ...

class GrobidClient:
    def __init__(self, base_url: str, timeout_seconds: float = 300.0,
                 health_timeout_seconds: float = 5.0,
                 transport: httpx.AsyncBaseTransport | None = None) -> None: ...

    async def is_alive(self) -> bool: ...
    async def extract_tei(self, pdf_bytes: bytes, filename: str) -> str: ...

def get_grobid_client(settings: Settings = Depends(get_settings)) -> GrobidClient:
    return GrobidClient(settings.grobid_url, settings.grobid_timeout_seconds,
                        settings.grobid_health_timeout_seconds)
```

Semantics (kept from the draft, now explicit):

| Condition | Result |
|---|---|
| `/api/isalive` 200 | `True`; any httpx error or non-200 | `False` |
| `/api/processFulltextDocument` connection/timeout error | `GrobidUnavailableError` |
| status ≥ 500 | `GrobidUnavailableError` |
| other non-200 | `GrobidExtractionError` |
| empty body | `GrobidExtractionError` |
| success | TEI XML text |

- Multipart call: `files={"input": (filename, pdf_bytes, "application/pdf")}` and
  `data={"teiCoordinates": ["ref", "biblStruct"]}` (repeated fields — do not join).
- One short-lived `httpx.AsyncClient(timeout=…)` per call, passing `transport=self._transport`
  when set. Comment why (low call volume from queue workers; no cross-request state; avoids
  lifespan/connection-pool plumbing). Phase 02 may revisit if embeddings need pooling.
- Health uses the dedicated health timeout; extract uses the extract timeout.

### 8.2 `src/inference/grobid/tei.py`

Move `tei_parser.py` content unchanged except:

- module imports,
- `class TEIParseError(ValueError): ...`; `parse_tei` raises it on `ET.ParseError`,
- keep helper names (`clean_text`, `parse_coords`, `extract_page_sizes`, `locate_text`, …).

Export `GrobidClient`, errors, `TEIParseError` and `parse_tei` from `grobid/__init__.py` for tidy
imports.

---

## 9. T4 — App factory, routers and handlers

### 9.1 `src/inference/main.py`

```python
def create_app() -> FastAPI:
    app = FastAPI(title="Dafpus Cek Document Extraction API",
                  description="Internal-only GROBID extraction service.",
                  version="1.1.0")
    app.add_exception_handler(StarletteHTTPException, http_exception_handler)
    app.add_exception_handler(RequestValidationError, validation_exception_handler)
    app.add_exception_handler(Exception, unhandled_exception_handler)
    app.include_router(health.router)
    app.include_router(extract.router)
    return app

app = create_app()
```

- `from starlette.exceptions import HTTPException as StarletteHTTPException` (D-INF-08). FastAPI's
  own `HTTPException` subclasses it, so both explicit raises and framework 404s hit the handler.
- Handlers keep the current code/message mapping (`BAD_REQUEST`, `NOT_FOUND`,
  `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_MEDIA_TYPE`, `VALIDATION_ERROR`, `INFERENCE_UNAVAILABLE`,
  `SERVER_ERROR`) and the `error_response()` helper.
- `unhandled_exception_handler` logs the exception and returns the `500 SERVER_ERROR` envelope
  (D-INF-09); it never includes the exception text.

### 9.2 `src/inference/api/health.py`

`GET /health`, `response_model=HealthData`, depends on `GrobidClient`; returns
`{"status": "ok"/"degraded", "grobid": "up"/"down"}` (no `sbert` until Phase 02).

### 9.3 `src/inference/api/extract.py`

`POST /v1/extract`, `response_model=ExtractResponse`, depends on `Settings` + `GrobidClient`.
Validation order (unchanged from the draft):

1. filename ends `.pdf` **and** content type in `{application/pdf, application/octet-stream, ""}`
   → else `415 UNSUPPORTED_MEDIA_TYPE` with `details.file`;
2. read at most `max_upload_bytes + 1`; over → `413 PAYLOAD_TOO_LARGE` with the MB hint;
3. empty or missing `%PDF-` magic → `422 EXTRACTION_FAILED`;
4. `extract_tei` → `GrobidUnavailableError` → `503 EXTRACTION_FAILED`;
5. `GrobidExtractionError` / `TEIParseError` → `422 EXTRACTION_FAILED`;
6. success → `{"data": parsed}`.

All failures raise `HTTPException(status_code=…, detail={"code": …, "message": …, "details": …})`
so the single handler renders the envelope. `await file.close()` after reading.

---

## 10. T5 — Tests, fixtures and harness (PR TODO 3)

### 10.1 Harness (`tests/conftest.py`)

```python
class GrobidStub:
    """Stateful MockTransport handler: recorded requests + programmable responses."""
    def __init__(self) -> None:
        self.requests: list[httpx.Request] = []
        self.responses: dict[str, httpx.Response | Exception] = {}

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        result = self.responses.get(request.url.path, httpx.Response(404))
        if isinstance(result, Exception):
            raise result
        return result

@pytest.fixture()
def grobid_stub() -> GrobidStub: ...

@pytest.fixture()
def client(grobid_stub) -> Iterator[TestClient]:
    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(
        grobid_url="http://grobid.test", max_upload_mb=1)
    app.dependency_overrides[get_grobid_client] = lambda: GrobidClient(
        base_url="http://grobid.test", transport=httpx.MockTransport(grobid_stub))
    with TestClient(app) as test_client:
        yield test_client
    app.dependency_overrides.clear()
```

- No `pytest-asyncio`: routes are exercised through the sync `TestClient`; `MockTransport`
  handlers may be sync.
- `max_upload_mb=1` keeps the `413` test small (2 MB total in memory); everything else is
  unaffected.
- Connection-error tests set `grobid_stub.responses["/api/processFulltextDocument"] =
  httpx.ConnectError("boom", request=httpx.Request("POST", "http://grobid.test/x"))` (or a tiny
  subclass) and assert `503`; timeout uses `httpx.ReadTimeout`.

### 10.2 Fixtures

- `tests/fixtures/documents/minimal.pdf` — the 368-byte `artikel.pdf` moved here; used as the
  mocked upload body.
- `tests/fixtures/tei/fulltext.tei.xml` — handcrafted GROBID-shaped fulltext TEI. Verified to
  parse correctly; the test asserts the parsed structure, not the XML. Minimum content:
  - `teiHeader`: `titleStmt/author/persName` (e.g. "Budi Santoso"), `publicationStmt/idno`
    type `DOI`, `profileDesc/abstract`;
  - `facsimile` with two `surface` (`lrx="595.276"`, `lry="841.89"`);
  - `body` with three `<ref type="bibr">` occurrences (coords on pages 1 and 2, targets `#b0`,
    `#b1`, `#b0`, one with an `&amp;`);
  - `back/listBibl` with two `biblStruct` (`xml:id="b0"` with DOI + journal + year,
    `xml:id="b1"` without DOI/authors to exercise nulls).
- `tests/fixtures/tei/empty.tei.xml` — minimal TEI with empty `body`/`back`.

### 10.3 Test matrix

| ID | Test file | Case | Expected |
|---|---|---|---|
| T-INF-01a | `test_health.py` | `isalive` 200 | `200`, `status=ok`, `grobid=up`; keys ⊆ `{status, grobid, sbert}` |
| T-INF-01b | `test_health.py` | `isalive` `ConnectError` | `200`, `status=degraded`, `grobid=down` |
| T-INF-01c | `test_health.py` | `isalive` 500 | `200`, `status=degraded`, `grobid=down` |
| T-INF-02a | `test_extract.py` | TEI fixture | `200`; `data.header.title`, `references[0].reference_index == 0`, `references[1].reference_index == 1`, citations `reference_index` 0/1/0, `occurrence_index` 0/1/2, locations with `coordinate_system`/`location_index`, `page_width == 595.276`, null DOI/authors on `b1` |
| T-INF-02b | `test_extract.py` | request contract on the stub | path `/api/processFulltextDocument`, multipart, `content` contains `name="teiCoordinates"` twice plus `ref` and `biblStruct` |
| T-INF-02c | `test_extract.py` | empty TEI | `200`, `references == []`, `citations == []`, header present |
| T-INF-02d | `test_extract.py` | GROBID `ConnectError` / `ReadTimeout` / 500 | `503`, `error.code == "EXTRACTION_FAILED"` |
| T-INF-02e | `test_extract.py` | GROBID 400 | `422 EXTRACTION_FAILED` |
| T-INF-02f | `test_extract.py` | 200 with empty body / malformed XML | `422 EXTRACTION_FAILED` |
| T-INF-03a | `test_validation.py` | missing `file` field | `422 VALIDATION_ERROR` + `details.file` |
| T-INF-03b | `test_validation.py` | `docx` name / `text/plain` | `415 UNSUPPORTED_MEDIA_TYPE` + `details.file` |
| T-INF-03c | `test_validation.py` | 1 MB + 1 body (cap 1 MB) | `413 PAYLOAD_TOO_LARGE` + details |
| T-INF-03d | `test_validation.py` | `.pdf` name, bytes not `%PDF-` / empty | `422 EXTRACTION_FAILED` |
| T-INF-03e | `test_validation.py` | `GET /nope` | `404 NOT_FOUND` envelope |
| T-INF-03f | `test_validation.py` | route raising (test-only) unhandled error, `TestClient(raise_server_exceptions=False)` | `500 SERVER_ERROR` envelope |
| T-INF-PARSER | `test_tei_parser.py` | coords `None`/garbage/partial/multi; page-size subtraction; DOI via `idno` + `ptr`; year via `when`/text; author join/dedup; `xml_id` stripped; invalid XML | exact values; `TEIParseError` |
| T-INF-SAMPLE | `test_tei_parser.py` | `docs/inference-extract-response.json` → `ExtractionData.model_validate` | passes (D-INF-10) |
| T-INF-LIVE | `test_integration.py` | real GROBID | skipped unless `GROBID_INTEGRATION_URL` and `GROBID_INTEGRATION_PDF` are set; asserts non-empty header/references and location shape |

The `500` test needs an app that raises: create `create_app()`, add a temporary
`@app.get("/boom")` route before calling `TestClient(..., raise_server_exceptions=False)`.

### 10.4 Assertion style

- Assert the exact `error.code` and presence of `message`; assert `details.file` only for
  validation cases.
- Parse the TEI fixture once per test module (`@pytest.fixture(scope="module")`).
- Do not assert full response snapshots; assert the fields the contract names.

---

## 11. T6 — Archive the exploration script (PR TODO 1)

- `git mv "grobid/grobid 1.0 .py" inference/scripts/grobid_exploration.py`.
- Header comment: historical/dev-only script, not imported by the service, not packaged (only
  `src/inference` ships), not covered by tests.
- Migrate `requests` → sync `httpx` (`httpx.post(url, files=…, data=[("teiCoordinates", "ref"),
  ("teiCoordinates", "biblStruct")], timeout=300)`, `response.status_code`, `response.text`) so it
  runs with project dependencies; no `requests` dependency is added.
- Add `inference/scripts/README.md` (three lines: purpose, how to run with `uv run python
  scripts/grobid_exploration.py`, explicit "not part of the service").
- `uv_build` packages only `src/inference`; nothing else is needed to exclude it.

---

## 12. T7 — Docs sync and final validation

| File | Change |
|---|---|
| `inference/README.md` | Rewrite: uv setup, run commands, endpoints, error codes/table, coordinate/offset notes, tests (`uv run pytest`, `-m integration`), project layout. Fold the useful parts of `grobid/README.md`. |
| `AGENTS.md` | §4 repo map (`inference/src/inference/**`, `scripts/`, `tests/`), §4 entry-point table (`inference/src/inference/main.py`), §14 status (GROBID slice implemented + tested; SBERT pending), §16 commands (`uv sync`, `uv run uvicorn inference.main:app --reload`, `uv run pytest`). |
| `docs/ARCHITECTURE.md` | §4 entry point line (`inference.main:app`), §10 note that `/health` currently reports `grobid` only, §13 status paragraph. |
| `docs/TEST_PLAN.md` | T-INF-03 → `415` wrong media type / `413` oversize / `422` invalid PDF; add T-INF-08/T-INF-09 rows for `413`/`415` if the table is kept one-case-per-row; mark T-INF-01/02/04 as implemented. |
| `docs/API_SPEC.md` §9 + `docs/API_SPEC_ID.md` §9 | Add `413 PAYLOAD_TOO_LARGE` and `415 UNSUPPORTED_MEDIA_TYPE` to the extract failure line (wording mirrors §2.5), keep `422`/`503` `EXTRACTION_FAILED`. No payload-shape change. |
| `docs/plans/inference/*.md` | Flip status headers of README/phase/detail to implemented and append the completion date. |

Final validation commands:

```bash
cd inference
uv lock --check
uv sync --locked
uv run pytest -q
uv run pytest -m integration -q          # skips without env, must not fail
uv run uvicorn inference.main:app        # manual smoke: /health, /v1/extract, /docs
cd ../backend
vendor/bin/pest tests/Feature/InferenceClientTest.php tests/Feature/InferenceDataTest.php
```

---

## 13. Files touched (summary)

```text
NEW       inference/src/inference/{api/{__init__,health,extract}.py, grobid/{__init__,client,tei}.py}
MOVED     inference/src/inference/{main,config,schemas}.py          ← grobid/app/*
MOVED     inference/.env.example, inference/.gitignore              ← grobid/
MOVED     inference/scripts/grobid_exploration.py                   ← "grobid 1.0 .py"
MOVED     inference/tests/fixtures/documents/minimal.pdf            ← grobid/artikel.pdf
DELETED   grobid/**, grobid/requirements.txt, inference/main.py, inference/.gitkeep
EDITED    inference/pyproject.toml, inference/uv.lock, inference/README.md
NEW       inference/tests/{conftest.py, test_health.py, test_extract.py, test_validation.py,
                           test_tei_parser.py, test_integration.py, fixtures/tei/*.tei.xml}
EDITED    AGENTS.md, docs/ARCHITECTURE.md, docs/TEST_PLAN.md,
          docs/API_SPEC.md §9, docs/API_SPEC_ID.md §9, docs/plans/inference/*
```

---

## 14. Risks, pitfalls and rollback

### 14.1 Pitfalls to avoid

- **Wrong HTTP exception class.** Registering on `fastapi.exceptions.HTTPException` leaves
  framework 404s outside the envelope (D-INF-08). Test it (T-INF-03e).
- **Joined `teiCoordinates`.** A single joined string silently drops coordinates; the request
  contract test (T-INF-02b) guards the repeated field.
- **Unbounded reads.** Always `read(max_upload_bytes + 1)`; never `await file.read()` for uploads.
- **`os.getenv` at import.** Breaks dependency overrides and makes the cap untestable; keep all
  config in `Settings`.
- **Editing the captured sample.** `docs/inference-extract-response.json` is evidence; if the
  schema rejects it, the schema (or sample) is wrong — investigate, don't trim.
- **Changing offset semantics.** Tests pin current behavior (references against full text,
  citations against body); a deliberate change needs a backend + spec discussion.
- **Hand-editing `uv.lock`.** Regenerate with `uv lock`; `uv lock --check` in the loop.
- **Sneaking in SBERT.** Phase 02 only; a half-added `sbert` field would break D-INF-03 and
  confuse `HealthData`.
- **Network in tests.** MockTransport only; the integration test must skip loudly, never retry.

### 14.2 Rollback

The PR is a draft and nothing on `main` imports `grobid/` or the inference package; reverting the
branch to `main` fully supersedes this phase. Within the branch, each task commit is revertible
independently (T1 is the only one that moves files).

---

## 15. Hand-off contract for Phase 02 (SBERT)

Phase 02 adds, without moving anything from this phase:

- `inference/src/inference/sbert/` (encoder wrapper, batched `embed`, `SBERTUnavailableError`),
- `inference/src/inference/api/embeddings.py` (`POST /v1/embeddings`),
- schema additions (`EmbeddingRequest`, `EmbeddingData`, `EmbeddingResponse`; `HealthData.sbert`),
- settings (`SBERT_MODEL`, `EMBEDDING_BATCH_SIZE=32` — the spec's batching note),
- health route composing both capability checks; T-INF-01 updated to assert `sbert`, TODO 4 closed,
- tests T-INF-05/06/07 (`422` empty texts, `503 INFERENCE_FAILED`, batching/dimension
  consistency); the embedding dependency (`sentence-transformers`/`torch`) is a Phase 02 decision.

The seams Phase 01 fixes for this: one package, `api/` router per contract group, `Settings` +
dependency overrides, `httpx.MockTransport` harness, and the `integration` marker.

---

## 16. Open questions / follow-ups (with recommended defaults)

| ID | Question | Recommended default | Owner |
|---|---|---|---|
| Q-1 | Real PDF fixture for the opt-in integration test (PR TODO 5) | Require `GROBID_INTEGRATION_PDF`; skip otherwise. Land curated fixtures in Phase 02 alongside the spec's `artikel.pdf` cleanup. | Phase 02 |
| Q-2 | `Dockerfile` referenced by the old `grobid/README.md` but never committed | Out of scope; pick up in the deployment phase (Phase 03) with resource limits and a GROBID version pin. | Phase 03 |
| Q-3 | Should `/health` include `"sbert": null` now for response-shape completeness? | No (D-INF-03): keep `{status, grobid}` and let Phase 02 add the real value; revisit only if a consumer needs the key early. | Phase 02 |
| Q-4 | `requires-python >= 3.14` limits contributor machines | Keep per D-INF-07; revisit before deployment if the target host does not ship 3.14. | Phase 03 |
| Q-5 | `TEIParseError` as `ValueError` subclass | Keep the subclass for backward-compatible `except ValueError` handling. | done |
| Q-6 | Should the exploration script keep duplicate parser logic? | Keep as a history/dev script only; never import from it and never test it. Delete it if it starts drifting. | Phase 02+ |
