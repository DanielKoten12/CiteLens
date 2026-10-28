"""Wire models for the internal inference contract (`docs/API_SPEC.md` §9).

Routes attach these models as ``response_model`` so the service validates its
own payload against the documented shape. Unknown fields are ignored (pydantic
default), which keeps the service forward-compatible with the captured real
response in ``docs/inference-extract-response.json``.
"""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator


class LocationData(BaseModel):
    """One bounding box of an extracted reference or citation region."""

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
    """Document-level metadata extracted from the TEI header."""

    title: str | None = None
    authors: list[str] = Field(default_factory=list)
    abstract: str | None = None
    publication_year: int | None = None
    doi: str | None = None


class ReferenceData(BaseModel):
    """One bibliography entry; GROBID cannot always parse every field."""

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
    """One in-text citation occurrence."""

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
    """Full extraction payload returned in ``data``."""

    header: HeaderData
    references: list[ReferenceData] = Field(default_factory=list)
    citations: list[CitationData] = Field(default_factory=list)


class ExtractResponse(BaseModel):
    """Envelope of ``POST /v1/extract``."""

    data: ExtractionData


class HealthData(BaseModel):
    """Liveness payload of ``GET /health``."""

    status: Literal["ok", "degraded"]
    grobid: Literal["up", "down"]
    sbert: Literal["up", "down"]


class EmbeddingRequest(BaseModel):
    """Body of ``POST /v1/embeddings``.

    ``model`` is the logical model name of the contract (`"sbert"`); the concrete
    checkpoint is service configuration (``SBERT_MODEL``), not a client choice.
    """

    texts: list[str] = Field(min_length=1)
    model: Literal["sbert"] = "sbert"

    @field_validator("texts")
    @classmethod
    def texts_must_not_be_blank(cls, texts: list[str]) -> list[str]:
        if any(not text.strip() for text in texts):
            raise ValueError("Setiap teks harus berisi minimal satu karakter.")
        return texts


class EmbeddingData(BaseModel):
    """Embedding payload returned in ``data``: one vector per requested text, in order."""

    model: Literal["sbert"]
    dimensions: int
    embeddings: list[list[float]]


class EmbeddingResponse(BaseModel):
    """Envelope of ``POST /v1/embeddings``."""

    data: EmbeddingData


class ErrorBody(BaseModel):
    code: str
    message: str
    details: dict[str, Any] | None = None


class ErrorResponse(BaseModel):
    """Standard error envelope for every non-2xx response."""

    error: ErrorBody
