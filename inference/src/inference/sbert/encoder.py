"""SBERT sentence-embedding capability (`docs/API_SPEC.md` §9).

The encoder owns model loading and inference only. It knows nothing about HTTP:
routes translate :class:`SbertUnavailableError` into the documented
``503 INFERENCE_FAILED``. Similarity scoring stays in Laravel
(`AGENTS.md` §12); this service only turns text into vectors.

``sentence_transformers`` (and therefore ``torch``) is imported lazily inside
the default loader, so importing the app and running the hermetic test suite
never pays for it. The ``loader`` argument is the test seam.
"""

from __future__ import annotations

import logging
import threading
from collections.abc import Callable, Sequence
from functools import lru_cache
from typing import Any, Protocol

from fastapi import Depends

from inference.config import Settings, get_settings

logger = logging.getLogger(__name__)


class SbertUnavailableError(RuntimeError):
    """The SBERT model could not be loaded or failed while encoding."""


class SentenceModel(Protocol):
    """The slice of ``SentenceTransformer`` this service relies on."""

    def encode(self, sentences: list[str], **kwargs: Any) -> Any: ...


def load_sentence_transformer(model_name: str) -> SentenceModel:
    """Load the model from the local Hugging Face cache, downloading on first use."""

    from sentence_transformers import SentenceTransformer

    return SentenceTransformer(model_name)


class SbertEncoder:
    """Lazy, thread-safe wrapper around a sentence-transformers model.

    The model is loaded on first use and then kept for the life of the process.
    A failed load is not cached, so a transient problem (e.g. the first model
    download losing connectivity) heals on the next request.
    """

    def __init__(
        self,
        model_name: str,
        batch_size: int = 32,
        loader: Callable[[str], SentenceModel] = load_sentence_transformer,
        max_text_chars: int = 2000,
    ) -> None:
        self._model_name = model_name
        self._batch_size = max(1, batch_size)
        self._max_text_chars = max(1, max_text_chars)
        self._loader = loader
        self._model: SentenceModel | None = None
        self._lock = threading.Lock()
        # One encode at a time: concurrent torch runs only oversubscribe the CPU
        # and multiply peak memory; callers (queue workers) simply queue up.
        self._encode_lock = threading.Lock()

    def _ensure_model(self) -> SentenceModel:
        with self._lock:
            if self._model is None:
                try:
                    self._model = self._loader(self._model_name)
                except Exception as exc:
                    logger.error("Could not load SBERT model %r", self._model_name, exc_info=exc)
                    raise SbertUnavailableError("SBERT model is unavailable.") from exc
            return self._model

    def is_loaded(self) -> bool:
        """Whether the model is already in memory. Never loads it and never blocks."""

        return self._model is not None

    def warm_up(self) -> bool:
        """Load the model now (blocking); ``False`` instead of raising on failure."""

        try:
            self._ensure_model()
        except SbertUnavailableError:
            return False
        return True

    def encode(self, texts: Sequence[str]) -> list[list[float]]:
        """Embed ``texts`` and return one L2-normalised vector per text, in order.

        Normalising makes cosine similarity a plain dot product for the caller
        and does not change cosine results.

        Each text is cut to ``max_text_chars`` first: the model truncates to its own
        token limit anyway (128 tokens for the default checkpoint), so this only
        bounds tokenisation cost for pathological input and never fails a request.

        Blocking and CPU/GPU bound: call it through a worker thread.

        Raises:
            SbertUnavailableError: the model cannot be loaded or inference fails.
        """

        model = self._ensure_model()

        clipped = [text[: self._max_text_chars] for text in texts]

        try:
            with self._encode_lock:
                vectors = model.encode(
                    clipped,
                    batch_size=self._batch_size,
                    normalize_embeddings=True,
                    convert_to_numpy=True,
                    show_progress_bar=False,
                )
            return [[float(value) for value in vector] for vector in vectors]
        except Exception as exc:
            logger.error("SBERT encoding failed", exc_info=exc)
            raise SbertUnavailableError("SBERT model is unavailable.") from exc


@lru_cache(maxsize=1)
def _shared_encoder(model_name: str, batch_size: int, max_text_chars: int) -> SbertEncoder:
    return SbertEncoder(
        model_name=model_name, batch_size=batch_size, max_text_chars=max_text_chars
    )


def get_sbert_encoder(settings: Settings = Depends(get_settings)) -> SbertEncoder:
    """One process-wide encoder, so the model is loaded once, not per request."""

    return _shared_encoder(
        settings.sbert_model, settings.embedding_batch_size, settings.embedding_max_text_chars
    )
