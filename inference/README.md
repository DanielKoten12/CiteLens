# Dafpus Cek — Internal Inference Service (FastAPI)

Internal-only service that extracts bibliography references, in-text citations and PDF coordinates
from a document through [GROBID](https://github.com/kermitt2/grobid), and turns text into SBERT
sentence embeddings for semantic similarity. Laravel queue workers call it over the private network
(`docs/API_SPEC.md` §9); it is **never** exposed publicly and never called by the frontend. It only
produces vectors; cosine similarity and the verdict are computed in Laravel.

## Layout

```text
inference/
├── pyproject.toml               # uv-managed; Python >= 3.14
├── uv.lock
├── .env.example
├── scripts/
│   └── grobid_exploration.py    # dev-only exploration script (not packaged or tested)
├── src/inference/
│   ├── main.py                  # create_app(), exception handlers, `app`
│   ├── config.py                # Settings + get_settings()
│   ├── schemas.py               # wire models (`API_SPEC.md` §9)
│   ├── api/                     # health.py, extract.py, embeddings.py
│   ├── grobid/                  # client.py (GrobidClient), tei.py (TEI -> JSON)
│   └── sbert/                   # encoder.py (SbertEncoder: lazy model load + encode)
└── tests/                       # pytest: hermetic MockTransport + opt-in integration
```

## Setup and run

```bash
cd inference
uv sync
cp .env.example .env             # GROBID_URL, timeouts, upload cap
uv run uvicorn inference.main:app --reload --env-file .env
```

GROBID must be reachable at `GROBID_URL` (default `http://localhost:8070`); in a container network
use the service name (e.g. `http://grobid:8070`). Interactive docs: `http://localhost:8000/docs`.

## Endpoints

### `GET /health`

`200` — liveness of the service and its capabilities:

```json
{ "status": "ok", "grobid": "up", "sbert": "up" }
```

`status` becomes `degraded` and `grobid` becomes `down` when GROBID cannot be reached, or answers
anything other than `200` on `/api/isalive`. `sbert` is `up` once the model is in
memory and `down` before that: `/health` never loads the model (the container healthcheck has a
short timeout). Set `SBERT_PRELOAD=true` to load it in the background at startup.

### `POST /v1/extract`

`multipart/form-data` with a single `file` field (PDF only, 20 MB default). Returns document
metadata, bibliography references, in-text citations and their PDF locations:

```bash
curl -X POST http://localhost:8000/v1/extract -F "file=@paper.pdf"
```

```json
{
  "data": {
    "header": { "title": "…", "authors": ["…"], "abstract": "…", "publication_year": 2024, "doi": "10.…" },
    "references": [
      {
        "reference_index": 0,
        "raw_text": "LeCun, Y., …",
        "title": "Deep learning",
        "authors": "LeCun, Y., Bengio, Y., & Hinton, G.",
        "publication_name": "Nature",
        "publication_year": 2019,
        "doi": "10.1038/nature14539",
        "text_start_offset": 8120,
        "text_end_offset": 8295,
        "locations": [
          { "page_number": 12, "x": 72.0, "y": 210.4, "width": 451.2, "height": 24.0,
            "page_width": 595.0, "page_height": 842.0,
            "coordinate_system": "pdf_points_top_left", "location_index": 0 }
        ]
      }
    ],
    "citations": [
      {
        "citation_text": "(LeCun et al., 2019)",
        "citation_marker": "(LeCun et al., 2019)",
        "context_before": "…",
        "context_after": "…",
        "text_start_offset": 2150,
        "text_end_offset": 2162,
        "occurrence_index": 0,
        "reference_index": 0,
        "locations": [ "…" ]
      }
    ]
  }
}
```

- `header` is document-level metadata; `title`, `abstract`, `publication_year`, `doi` are `null`
  when GROBID cannot parse them and `authors` may be empty.
- `reference_index` is the 0-based payload position of the entry; a citation's `reference_index`
  points at it (`null` when GROBID cannot link the occurrence).
- `locations[]` boxes are 1-based `page_number`, `pdf_points_top_left` coordinates, and a 0-based
  `location_index`; a reference or citation may span several boxes.
- Offsets index the whitespace-normalized TEI text: references are located in the full `<text>`
  (body + bibliography), citations in the `<body>`. They are `null` when the extracted text does
  not match; the persistence layer reindexes occurrences deterministically.

**Failure** responses use the standard envelope (`{ "error": { "code", "message", "details"? } }`):

| Status | Code | When |
|---|---|---|
| `413` | `PAYLOAD_TOO_LARGE` | File over `MAX_UPLOAD_MB` |
| `415` | `UNSUPPORTED_MEDIA_TYPE` | Not a PDF (extension/content type) |
| `422` | `VALIDATION_ERROR` | Missing `file` field |
| `422` | `EXTRACTION_FAILED` | Empty/invalid PDF bytes or unusable TEI |
| `503` | `EXTRACTION_FAILED` | GROBID unreachable or 5xx |

### `POST /v1/embeddings`

JSON body with `texts` (non-empty list of non-blank strings) and optional `model` (`"sbert"`).
Returns one L2-normalised vector per text, in request order:

```bash
curl -X POST http://localhost:8000/v1/embeddings   -H "Content-Type: application/json"   -d '{"texts": ["Deep learning", "Pembelajaran mendalam"], "model": "sbert"}'
```

```json
{ "data": { "model": "sbert", "dimensions": 768, "embeddings": [[0.021, -0.133, "…"], [0.004, 0.088, "…"]] } }
```

Vectors are normalised, so the cosine similarity of two texts is the dot product of their vectors.
The service does not compute similarity or decide validity — Laravel does (`AGENTS.md` §12).
Laravel already batches (default 32 texts per call); inside a request the model batches by
`EMBEDDING_BATCH_SIZE`.

| Status | Code | When |
|---|---|---|
| `422` | `VALIDATION_ERROR` | `texts` missing/empty/blank, unknown `model`, or more than `EMBEDDING_MAX_TEXTS` texts |
| `503` | `INFERENCE_FAILED` | The model cannot be loaded or encoding failed |

The model is loaded lazily on first use and kept in memory; the first request after a cold start
downloads it from Hugging Face (~1 GB) and can take minutes, so keep a persistent `HF_HOME`.

## Tests

```bash
cd inference
uv run pytest                    # hermetic: httpx.MockTransport + fake SBERT model, no torch load
uv run pytest -m integration     # opt-in live GROBID (skipped by default)
uv lock --check
```

The integration test runs only when both variables are set:

```bash
GROBID_INTEGRATION_URL=http://localhost:8070 \
GROBID_INTEGRATION_PDF=/path/to/paper.pdf \
uv run pytest -m integration
```

## Configuration

| Env var | Default | Purpose |
|---|---|---|
| `GROBID_URL` | `http://localhost:8070` | GROBID base URL |
| `GROBID_TIMEOUT_SECONDS` | `300` | Extract read timeout |
| `GROBID_HEALTH_TIMEOUT_SECONDS` | `5` | `/api/isalive` timeout |
| `MAX_UPLOAD_MB` | `20` | Upload cap (mirrors the public API) |
| `SBERT_MODEL` | `sentence-transformers/paraphrase-multilingual-mpnet-base-v2` | Embedding model (768 dims, multilingual) |
| `EMBEDDING_BATCH_SIZE` | `32` | Model batch size inside one request |
| `EMBEDDING_MAX_TEXTS` | `256` | Max texts per `/v1/embeddings` request (else `422`) |
| `EMBEDDING_MAX_TEXT_CHARS` | `2000` | Each text is cut to this length before encoding (never an error) |
| `SBERT_PRELOAD` | `false` | Load the model in a background thread at startup |

`.env` is git-ignored; never commit real values. The service is private-network only — do not
expose it to the internet, and do not add public/frontend-facing endpoints here.
