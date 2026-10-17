# syntax=docker/dockerfile:1
#
# Internal inference service (FastAPI + GROBID client). Private network only;
# it must never be published or called by the browser (docs/SECURITY.md §7).
#
# Build context is the repository root (see compose.yaml), so paths below are
# repository-relative.

FROM ghcr.io/astral-sh/uv:python3.14-bookworm-slim AS base

ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/app/.venv \
    PYTHONUNBUFFERED=1 \
    PATH="/app/.venv/bin:$PATH"

WORKDIR /app

# Runtime dependencies first so source edits do not re-resolve the lockfile.
RUN --mount=type=cache,target=/root/.cache/uv \
    --mount=type=bind,source=inference/pyproject.toml,target=pyproject.toml \
    --mount=type=bind,source=inference/uv.lock,target=uv.lock \
    uv sync --frozen --no-install-project --no-dev

COPY inference/pyproject.toml inference/uv.lock inference/README.md ./
COPY inference/src ./src

# ---------------------------------------------------------------------------
# Development: source is bind-mounted by compose.yaml and uvicorn reloads it;
# the dev dependency group (pytest) is installed so tests can run in-container.
# ---------------------------------------------------------------------------
FROM base AS dev

RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen

COPY inference/tests ./tests

EXPOSE 8000

CMD ["uvicorn", "inference.main:app", "--host", "0.0.0.0", "--port", "8000", "--reload"]

# ---------------------------------------------------------------------------
# Production: no dev dependencies, non-editable install, unprivileged user.
# ---------------------------------------------------------------------------
FROM base AS prod

RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev --no-editable \
    && useradd --create-home --uid 10001 app \
    && chown -R app:app /app

USER app

EXPOSE 8000

CMD ["uvicorn", "inference.main:app", "--host", "0.0.0.0", "--port", "8000"]
