"""Shared test helpers: a programmable GROBID transport and app builder.

Kept outside ``conftest`` so test modules can import the types and helpers
directly (pytest puts this directory on ``sys.path`` for test modules).
"""

from __future__ import annotations

from typing import Any

import httpx
from fastapi import FastAPI
from fastapi.testclient import TestClient

from inference.config import Settings, get_settings
from inference.grobid import GrobidClient, get_grobid_client
from inference.main import create_app

GROBID_TEST_URL = "http://grobid.test"


class GrobidStub:
    """Stateful ``httpx.MockTransport`` handler: records requests, routes responses."""

    def __init__(self) -> None:
        self.requests: list[httpx.Request] = []
        self.responses: dict[str, httpx.Response | Exception] = {}

    def respond(self, path: str, response: httpx.Response | Exception) -> None:
        self.responses[path] = response

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        result = self.responses.get(request.url.path)
        if result is None:
            return httpx.Response(404)
        if isinstance(result, Exception):
            raise result
        return result


def connect_error(path: str = "/api/processFulltextDocument") -> httpx.ConnectError:
    return httpx.ConnectError(
        "connection refused",
        request=httpx.Request("POST", f"{GROBID_TEST_URL}{path}"),
    )


def read_timeout(path: str = "/api/processFulltextDocument") -> httpx.ReadTimeout:
    return httpx.ReadTimeout(
        "timed out",
        request=httpx.Request("POST", f"{GROBID_TEST_URL}{path}"),
    )


def minimal_pdf_bytes() -> bytes:
    """Build a structurally valid one-page PDF for upload tests.

    The hermetic suite stubs GROBID, so the upload body only has to be a real
    PDF (header, objects, xref, trailer). The opt-in integration test reads a
    real paper from ``GROBID_INTEGRATION_PDF`` instead.
    """

    content = b"BT /F1 12 Tf 72 720 Td (Dafpus Cek) Tj ET"
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
        b"/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        b"<< /Length "
        + str(len(content)).encode()
        + b" >>\nstream\n"
        + content
        + b"\nendstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]

    chunks: list[bytes] = [b"%PDF-1.4\n"]
    offsets: list[int] = []
    position = len(chunks[0])
    for index, body in enumerate(objects, start=1):
        offsets.append(position)
        chunk = f"{index} 0 obj\n".encode() + body + b"\nendobj\n"
        chunks.append(chunk)
        position += len(chunk)

    chunks.append(b"xref\n" + f"0 {len(objects) + 1}\n".encode() + b"0000000000 65535 f \n")
    chunks.extend(f"{offset:010d} 00000 n \n".encode() for offset in offsets)
    chunks.append(
        b"trailer\n"
        + f"<< /Size {len(objects) + 1} /Root 1 0 R >>\n".encode()
        + b"startxref\n"
        + f"{position}\n".encode()
        + b"%%EOF\n"
    )
    return b"".join(chunks)


def make_app(stub: GrobidStub, *, max_upload_mb: int = 20) -> FastAPI:
    """Build the real app with settings and the GROBID client overridden."""

    app = create_app()
    app.dependency_overrides[get_settings] = lambda: Settings(
        grobid_url=GROBID_TEST_URL,
        max_upload_mb=max_upload_mb,
    )
    app.dependency_overrides[get_grobid_client] = lambda: GrobidClient(
        base_url=GROBID_TEST_URL,
        transport=httpx.MockTransport(stub),
    )
    return app


def make_client(stub: GrobidStub, *, max_upload_mb: int = 20, **kwargs: Any) -> TestClient:
    return TestClient(make_app(stub, max_upload_mb=max_upload_mb), **kwargs)
