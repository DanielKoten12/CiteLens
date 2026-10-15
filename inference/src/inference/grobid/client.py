"""Async client for the internal GROBID service (`docs/API_SPEC.md` §9).

The client owns transport concerns only: request shape, timeouts and typed
failures. Routes translate those failures into the documented HTTP statuses.
The optional ``transport`` is the test seam (``httpx.MockTransport``).
"""

from __future__ import annotations

import httpx
from fastapi import Depends

from inference.config import Settings, get_settings


class GrobidUnavailableError(RuntimeError):
    """GROBID could not be reached or returned a transient failure (5xx)."""


class GrobidExtractionError(RuntimeError):
    """GROBID rejected the document or returned an unusable response."""


class GrobidClient:
    """Thin async wrapper around the GROBID HTTP API.

    A short-lived ``AsyncClient`` is opened per call: the service is called by
    queue workers at low volume and carries no cross-request state, so per-call
    clients keep lifecycle handling out of the app. Revisit if embedding
    traffic ever needs connection pooling.
    """

    def __init__(
        self,
        base_url: str,
        timeout_seconds: float = 300.0,
        health_timeout_seconds: float = 5.0,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._timeout_seconds = timeout_seconds
        self._health_timeout_seconds = health_timeout_seconds
        self._transport = transport

    async def is_alive(self) -> bool:
        """Whether GROBID answers ``/api/isalive``; never raises."""

        try:
            async with self._new_client(self._health_timeout_seconds) as client:
                response = await client.get(f"{self._base_url}/api/isalive")
                return response.status_code == 200
        except httpx.HTTPError:
            return False

    async def extract_tei(self, pdf_bytes: bytes, filename: str) -> str:
        """Run fulltext extraction and return the TEI XML.

        ``teiCoordinates`` is sent twice (once per value), which is what GROBID
        expects; joining the values into a single field disables coordinates.

        Raises:
            GrobidUnavailableError: connection/timeout errors and 5xx responses.
            GrobidExtractionError: other non-200 responses and empty bodies.
        """

        files = {"input": (filename, pdf_bytes, "application/pdf")}
        data = {"teiCoordinates": ["ref", "biblStruct"]}

        try:
            async with self._new_client(self._timeout_seconds) as client:
                response = await client.post(
                    f"{self._base_url}/api/processFulltextDocument",
                    files=files,
                    data=data,
                )
        except httpx.HTTPError as exc:
            raise GrobidUnavailableError("GROBID is unavailable") from exc

        if response.status_code >= 500:
            raise GrobidUnavailableError("GROBID is unavailable")

        if response.status_code != 200:
            raise GrobidExtractionError("GROBID could not process the document")

        tei_xml = response.text
        if not tei_xml.strip():
            raise GrobidExtractionError("GROBID returned empty TEI XML")

        return tei_xml

    def _new_client(self, timeout_seconds: float) -> httpx.AsyncClient:
        return httpx.AsyncClient(
            timeout=httpx.Timeout(timeout_seconds),
            transport=self._transport,
        )


def get_grobid_client(settings: Settings = Depends(get_settings)) -> GrobidClient:
    return GrobidClient(
        base_url=settings.grobid_url,
        timeout_seconds=settings.grobid_timeout_seconds,
        health_timeout_seconds=settings.grobid_health_timeout_seconds,
    )
