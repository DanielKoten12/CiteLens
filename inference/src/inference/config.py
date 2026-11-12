"""Environment-driven settings for the internal inference service.

Values are read once per process through :func:`get_settings`; routes receive
them as FastAPI dependencies so tests can override them without touching the
environment or module globals (see the Phase 01 plan, T2).
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from functools import lru_cache

DEFAULT_GROBID_URL = "http://localhost:8070"
DEFAULT_GROBID_TIMEOUT_SECONDS = 300.0
DEFAULT_GROBID_HEALTH_TIMEOUT_SECONDS = 5.0
DEFAULT_MAX_UPLOAD_MB = 20
# 768-dimensional multilingual model: handles the Indonesian + English titles
# the product sees and matches the `dimensions` shown in `docs/API_SPEC.md` §9.
DEFAULT_SBERT_MODEL = "sentence-transformers/paraphrase-multilingual-mpnet-base-v2"
DEFAULT_EMBEDDING_BATCH_SIZE = 32
DEFAULT_EMBEDDING_MAX_TEXTS = 256
DEFAULT_EMBEDDING_MAX_TEXT_CHARS = 2000


@dataclass(frozen=True, slots=True)
class Settings:
    """Runtime configuration; every field has a sane default for tests."""

    grobid_url: str = DEFAULT_GROBID_URL
    grobid_timeout_seconds: float = DEFAULT_GROBID_TIMEOUT_SECONDS
    grobid_health_timeout_seconds: float = DEFAULT_GROBID_HEALTH_TIMEOUT_SECONDS
    max_upload_mb: int = DEFAULT_MAX_UPLOAD_MB
    sbert_model: str = DEFAULT_SBERT_MODEL
    embedding_batch_size: int = DEFAULT_EMBEDDING_BATCH_SIZE
    embedding_max_texts: int = DEFAULT_EMBEDDING_MAX_TEXTS
    embedding_max_text_chars: int = DEFAULT_EMBEDDING_MAX_TEXT_CHARS
    sbert_preload: bool = False

    @property
    def max_upload_bytes(self) -> int:
        return max(1, self.max_upload_mb) * 1024 * 1024

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            grobid_url=os.getenv("GROBID_URL", DEFAULT_GROBID_URL).rstrip("/"),
            grobid_timeout_seconds=float(
                os.getenv("GROBID_TIMEOUT_SECONDS", str(DEFAULT_GROBID_TIMEOUT_SECONDS))
            ),
            grobid_health_timeout_seconds=float(
                os.getenv("GROBID_HEALTH_TIMEOUT_SECONDS", str(DEFAULT_GROBID_HEALTH_TIMEOUT_SECONDS))
            ),
            max_upload_mb=int(os.getenv("MAX_UPLOAD_MB", str(DEFAULT_MAX_UPLOAD_MB))),
            sbert_model=os.getenv("SBERT_MODEL", DEFAULT_SBERT_MODEL),
            embedding_batch_size=int(
                os.getenv("EMBEDDING_BATCH_SIZE", str(DEFAULT_EMBEDDING_BATCH_SIZE))
            ),
            embedding_max_texts=int(
                os.getenv("EMBEDDING_MAX_TEXTS", str(DEFAULT_EMBEDDING_MAX_TEXTS))
            ),
            embedding_max_text_chars=int(
                os.getenv("EMBEDDING_MAX_TEXT_CHARS", str(DEFAULT_EMBEDDING_MAX_TEXT_CHARS))
            ),
            sbert_preload=os.getenv("SBERT_PRELOAD", "false").strip().lower() in {"1", "true", "yes"},
        )


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings.from_env()
