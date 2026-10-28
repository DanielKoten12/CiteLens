"""SBERT capability: sentence embeddings for semantic title similarity."""

from inference.sbert.encoder import (
    SbertEncoder,
    SbertUnavailableError,
    get_sbert_encoder,
)

__all__ = [
    "SbertEncoder",
    "SbertUnavailableError",
    "get_sbert_encoder",
]
