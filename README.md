# Dafpus Cek

TODO: Update this README.md

Academic-reference verification: upload a PDF and the system extracts its bibliography and in-text
citations (GROBID), validates each reference against external metadata (Crossref first, with
Garuda/Semantic Scholar/OpenAlex fallbacks), ranks candidate matches,
resolves citations to references and reports problems such as hallucinated DOIs, unverifiable
references, missing DOIs and citation/reference mismatches.

> UI branding: **CiteLens**. The proposal calls the product **Dafpus Cek**; the repository name is
> **Dafpus Sitasi Halu** — all three refer to this project (`AGENTS.md` §1).

## Architecture

```text
Vue 3 SPA ──REST/JSON──▶ Laravel API ──▶ PostgreSQL
                              │  ├──▶ queue worker (async analysis + reports)
                              │  ├──▶ metadata sources (outbound: Crossref → Garuda →
                              │  │      Semantic Scholar → OpenAlex)
                              │  ├──▶ FastAPI inference (private) ──▶ GROBID
                              │  └──▶ Gotenberg (private; report PDFs)
                              └──
```

Canonical contracts: [`docs/API_SPEC.md`](docs/API_SPEC.md) (public API),
[`docs/DB_SCHEMA.md`](docs/DB_SCHEMA.md) (domain schema). Architecture, security and test
expectations: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), [`docs/SECURITY.md`](docs/SECURITY.md),
[`docs/TEST_PLAN.md`](docs/TEST_PLAN.md).

## Quick start (Docker)

Prerequisites: Docker Engine with Compose 2.24+ (Docker Desktop / OrbStack ship it; the overlays use
`!override` merge tags).

```bash
# Development stack, GROBID CRF by default (multi-arch: amd64 + arm64, no emulation)
docker compose up -d --build

# GROBID full models instead of CRF (amd64 only; emulated on arm64 hosts)
docker compose -f compose.yaml -f compose.full.yaml up -d --build

# No local GROBID: use a hosted one (sends uploads to a third party; dev/demo only)
docker compose -f compose.yaml -f compose.remote-grobid.yaml up -d --build
```

- SPA: <http://localhost:5173>
- Laravel API: <http://localhost:8000/api/v1> (`GET /up` for liveness)
- First build downloads the GROBID image and takes a while; `docker compose logs -f` follows startup.

The analysis pipeline requires the internal services (PostgreSQL, inference, GROBID, Gotenberg),
all of which the stack starts. Semantic scoring is disabled in containers
(`SCORING_SEMANTIC_ENABLED=false`) because the SBERT `POST /v1/embeddings` capability is not
implemented yet — see the runbook for details and caveats. Semantic Scholar and OpenAlex are
optional supplemental metadata sources and stay disabled unless `SEMANTIC_SCHOLAR_API_KEY` /
`OPENALEX_API_KEY` are configured (see `.env.example`).

Production-shaped stack and debug port publishing:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
docker compose -f compose.yaml -f compose.debug.yaml up -d
```

Full runbook: [`docker/README.md`](docker/README.md).

## Running the components without Docker

| Component | Setup | Checks |
|---|---|---|
| Backend (`backend/`) | `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate` | `php artisan test`, `vendor/bin/pint --dirty` |
| Frontend (`frontend/`) | `npm install` | `npm run type-check`, `npm run build` |
| Inference (`inference/`) | `uv sync` | `uv run pytest` |

See [`AGENTS.md`](AGENTS.md) §16 for the verified command list and
[`docs/TEST_PLAN.md`](docs/TEST_PLAN.md) for the test strategy.

## Scope note

v1 accepts **PDF only** (max 20 MB), does not process scanned PDFs without a text layer, and is a
reference/citation verifier — not a plagiarism, grammar or writing-quality tool
(`AGENTS.md` §1, §9).
