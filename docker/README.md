# Docker Runbook — Dafpus Cek

Container packaging for every component of the stack. The canonical specs stay the source of
truth for behavior; this document only covers how to build, run and operate the containers.

## 1. Services

| Compose service | Image / build | Published by default | Role |
|---|---|---|---|
| `frontend` | `frontend/Dockerfile` (`dev`/`prod` targets) | `http://localhost:5173` (dev) / `:8080` (prod) | Vue SPA (CiteLens); dev = Vite HMR, prod = static bundle in nginx |
| `web` | `nginx:1.29-alpine` (dev) / `docker/nginx/Dockerfile` (prod) | `http://localhost:8000` | Laravel edge: `public/` + php-fpm upstream |
| `backend` | `backend/Dockerfile` (`dev`/`prod` targets) | — | php-fpm; runs migrations once at start |
| `worker` | same image as `backend` | — | `php artisan queue:work` (analysis + reports) |
| `db` | `postgres:18-alpine` | — | PostgreSQL 18 (named volume `db-data`) |
| `inference` | `inference/Dockerfile` (`dev`/`prod` targets) | — | FastAPI + GROBID client (`docs/API_SPEC.md` §9) |
| `grobid` | `grobid/grobid:0.9.1-full` (amd64) / `0.9.1-crf` (arm64) | — | PDF reference/citation extraction |
| `gotenberg` | `gotenberg/gotenberg:8` | — | HTML→PDF rendering for reports |

Only `web` and `frontend` are reachable from the host. PostgreSQL, inference, GROBID and Gotenberg
stay on the private compose network (`docs/SECURITY.md` §7); use `compose.debug.yaml` when you need
direct access for debugging.

## 2. Running

```bash
# Development, amd64 default (GROBID full image, runs via emulation on arm64/hosts)
docker compose up -d --build

# Apple Silicon / arm64 hosts: native GROBID CRF image
docker compose -f compose.yaml -f compose.arm64.yaml up -d --build

# Production-shaped stack (baked images, SPA served by nginx, non-root inference service)
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
docker compose -f compose.yaml -f compose.prod.yaml -f compose.arm64.yaml up -d --build

# Opt-in: publish the private services on 127.0.0.1 for inspection
docker compose -f compose.yaml -f compose.debug.yaml up -d
#   PostgreSQL 5432 · GROBID 8070 · inference 8001 → 8000 · Gotenberg 3000

# Useful day-to-day commands
docker compose ps
docker compose logs -f backend worker
docker compose exec backend php artisan migrate:status
docker compose down            # stop, keep data volumes
docker compose down -v         # stop and delete volumes (DB, storage, vendor, node_modules)
```

Health/readiness: `db` (`pg_isready`) → `backend` (php-fpm socket, which only listens after
migrations complete) → `worker` and `web`. `inference` waits for GROBID's healthcheck; the SPA
waits for `web`'s `/up` check.

## 3. Configuration

All compose variables have development defaults in `compose.yaml`. To override, copy the root
template and edit:

```bash
cp .env.example .env
```

Notable variables: `WEB_PORT`, `FRONTEND_PORT`, `FRONTEND_PROD_PORT`, `POSTGRES_*`, `APP_KEY`,
`APP_URL`, `CROSSREF_MAILTO`, `SCORING_SEMANTIC_ENABLED`, and the `*_DEBUG_PORT` values used by
`compose.debug.yaml`.

### Why the root `.env` and not per-component `.env` files

`.env` does two different jobs, and only the root file covers both:

| Job | Read by | Location |
|---|---|---|
| Compose interpolation (`${WEB_PORT}`, `${POSTGRES_PASSWORD}`, `${COMPOSE_PROJECT_NAME}`, ...) | the Compose CLI, before containers start | **root only** — Compose resolves `.env` next to `compose.yaml` and has no per-service interpolation |
| Application runtime config (`env()`, `GROBID_URL`, `VITE_API_BASE_URL`) | the process inside the container | injected by compose `environment:` / build args |

The per-component templates (`backend/.env.example`, `inference/.env.example`,
`frontend/.env.example`) still exist for **running components on the host** (they assume sqlite,
`localhost:8070`, etc.) and are deliberately *not* mounted into containers:

1. **Precedence trap.** Laravel's Dotenv repository is immutable, so a real process env var beats a
   mounted file — some keys would silently come from compose and others from the file. Python
   (`os.getenv`) and Vite use different precedence rules again. One direction only: compose
   `environment` → container.
2. **Different contexts.** Host files say `DB_CONNECTION=sqlite` and
   `GROBID_URL=http://localhost:8070/`; containers need `pgsql` + `DB_HOST=db` and
   `http://grobid:8070/`. Mounting host files ships host assumptions into the network.
3. **One auditable source of truth.** `docker compose config` shows the effective environment for
   every container; mounted `.env` files are invisible to that check and drift silently.
4. **Secrets stay put.** Untracked host `.env` files often hold real local keys and are not copied
   into images/containers (`.dockerignore` excludes them; `APP_KEY` is handled by the entrypoint).

If these images ever need to run **without** Compose (plain `docker run`, Kubernetes), switch to a
committed, non-secret `backend/.env.docker` / `inference/.env.docker` wired through `env_file:` and
keep the root `.env` for interpolation variables only. Explicit compose `environment:` entries
always win over `env_file`, so the two can coexist — the cost is a second place to maintain
defaults.

### APP_KEY

- `APP_KEY` from the environment (root `.env`) always wins — use this outside local development.
  Generate one with `docker compose run --rm backend php artisan key:generate --show` or
  `php artisan key:generate --show` on the host.
- When unset, the backend entrypoint generates a key once and stores it at
  `storage/app/private/.docker-app-key` on the shared `backend-storage` volume, so `backend` and
  `worker` always share one key across restarts. Deleting volumes (`down -v`) rotates it and
  invalidates existing tokens.

### Database

PostgreSQL 18 stores its data under `/var/lib/postgresql/18/docker`; the `db-data` volume is
mounted at `/var/lib/postgresql` accordingly (`docker-library/postgres#1259`). Migrations run
automatically in the `backend` entrypoint (`RUN_MIGRATIONS=true`, one writer only).

### File storage

Uploaded PDFs and generated reports live on the `backend-storage` volume
(`storage/app/private`, disks `local`/`reports`). Reports are rendered through Gotenberg and
downloaded via the API's private-file URLs. The volume survives `docker compose down`; only
`down -v` deletes it.

## 4. Tests inside containers

```bash
docker compose exec backend php artisan test      # Pest (in-memory SQLite, sync queue)
docker compose exec backend vendor/bin/pint --dirty
docker compose exec inference uv run pytest       # hermetic contract suite
docker compose exec frontend npm run type-check
```

The `dev` targets carry the development dependencies these commands need. The production-shaped
images do not.

## 5. Caveats and known gaps

- **SBERT / `/v1/embeddings` is not implemented** (inference Phase 02). A 404 from that endpoint is
  fatal to the `embedding` pipeline step, so the compose environment sets
  `SCORING_SEMANTIC_ENABLED=false` and scoring degrades to string signals
  (`EmbedReferencesStep` degradation policy). Remove the override once SBERT lands.
- **GROBID variant**: the default amd64 image is the `-full` (deep-learning) build; the arm64
  overlay uses `-crf` (CRF models only) because `-full` has no arm64 manifest. CRF covers
  bibliography + in-text citation extraction; full-image accuracy is higher for reference parsing
  and citation contexts.
- **GROBID memory**: the service is JVM-based and needs several GB of RAM. On Docker Desktop,
  raise the VM memory limit if extraction fails or the container is OOM-killed. The full image on
  arm64 hosts runs under emulation: expect it to be slow.
- **Upload limits**: nginx (`client_max_body_size 25m`) and PHP (`upload_max_filesize 25M`,
  `post_max_size 26M`) are deliberately above the 20 MB API cap so Laravel answers with the
  documented `413 PAYLOAD_TOO_LARGE` envelope instead of an HTML error page.
- **Queue timing**: `DB_QUEUE_RETRY_AFTER=980` and the worker `--timeout=960` must stay above
  `ANALYSIS_TIMEOUT=900`, otherwise long analysis jobs are released and re-run.
- **Dependencies in development**: `vendor`, `node_modules` and `.venv` are named volumes built
  into the images. After changing `composer.json`/`package.json`/`pyproject.toml`, rebuild
  (`docker compose build`) or run `docker compose exec <service> <install command>` and recreate
  the volume.
- **`route:cache` is not warmed** because `routes/web.php` defines a closure route; production
  caching covers `config` and views only.
- **No scheduler container**: `routes/console.php` declares no scheduled tasks. Add a
  `php artisan schedule:work` service when one is introduced (same image as `backend`).

## 6. Troubleshooting

| Symptom | Check |
|---|---|
| `port is already allocated` | Another local service owns 8000/5173/8080. Change `WEB_PORT`/`FRONTEND_PORT`/`FRONTEND_PROD_PORT` in `.env`. |
| `worker` stays in `created` | `backend` is not healthy yet: `docker compose logs backend` (migrations, database wait, APP_KEY). |
| `inference` stays in `created` | GROBID has not passed `/api/isalive` (JVM startup can take a minute). `docker compose logs grobid`. |
| Analysis fails with `INFERENCE_UNAVAILABLE` | GROBID crashed or is emulated too slowly. Check `docker compose logs grobid` and Docker Desktop memory. |
| Analysis fails at `embedding` | `SCORING_SEMANTIC_ENABLED` must be `false` until SBERT exists. |
| 413 from nginx/PHP instead of the API envelope | Someone lowered the nginx/PHP limits below the 20 MB cap; restore them. |
| Stale PHP/Node/Python dependencies after a lockfile change | `docker compose build <service>` then `docker compose down -v <volume>` (or `docker compose up -d --force-recreate`). |
