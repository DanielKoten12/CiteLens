"""GROBID capability: TEI parsing and the internal HTTP client."""

from inference.grobid.client import (
    GrobidClient,
    GrobidExtractionError,
    GrobidUnavailableError,
    get_grobid_client,
)
from inference.grobid.tei import TEIParseError, parse_tei

__all__ = [
    "GrobidClient",
    "GrobidExtractionError",
    "GrobidUnavailableError",
    "TEIParseError",
    "get_grobid_client",
    "parse_tei",
]
