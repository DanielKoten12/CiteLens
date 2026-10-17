from __future__ import annotations

import logging

import httpx
from fastapi.testclient import TestClient

from support import GrobidStub, make_app, make_client


def test_extract_requires_a_file(client: TestClient) -> None:
    response = client.post("/v1/extract", data={})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert body["error"]["message"] == "Data permintaan tidak valid."
    assert "file" in body["error"]["details"]


def test_extract_rejects_a_non_pdf_filename(client: TestClient, pdf_bytes: bytes) -> None:
    response = client.post(
        "/v1/extract", files={"file": ("paper.docx", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 415
    body = response.json()
    assert body["error"]["code"] == "UNSUPPORTED_MEDIA_TYPE"
    assert body["error"]["details"]["file"] == ["File harus berupa PDF."]


def test_extract_rejects_a_non_pdf_content_type(client: TestClient, pdf_bytes: bytes) -> None:
    response = client.post("/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "text/plain")})

    assert response.status_code == 415
    assert response.json()["error"]["code"] == "UNSUPPORTED_MEDIA_TYPE"


def test_extract_rejects_a_payload_over_the_configured_cap(grobid_stub: GrobidStub) -> None:
    oversized = b"%PDF-" + b"0" * (1024 * 1024)

    with make_client(grobid_stub, max_upload_mb=1) as client:
        response = client.post(
            "/v1/extract", files={"file": ("paper.pdf", oversized, "application/pdf")}
        )

    assert response.status_code == 413
    body = response.json()
    assert body["error"]["code"] == "PAYLOAD_TOO_LARGE"
    assert body["error"]["details"]["file"] == ["Ukuran maksimum 1 MB."]


def test_extract_rejects_bytes_without_the_pdf_magic(client: TestClient) -> None:
    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", b"not a pdf", "application/pdf")}
    )

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "EXTRACTION_FAILED"
    assert body["error"]["details"]["file"] == ["File kosong atau bukan PDF yang valid."]


def test_extract_rejects_an_empty_file(client: TestClient) -> None:
    response = client.post("/v1/extract", files={"file": ("paper.pdf", b"", "application/pdf")})

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "EXTRACTION_FAILED"


def test_extract_accepts_an_octet_stream_part(
    client: TestClient, grobid_stub: GrobidStub, fulltext_tei: str, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=fulltext_tei))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/octet-stream")}
    )

    assert response.status_code == 200


def test_extract_accepts_a_content_type_with_parameters(
    client: TestClient, grobid_stub: GrobidStub, fulltext_tei: str, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=fulltext_tei))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf; charset=binary")}
    )

    assert response.status_code == 200


def test_unknown_route_uses_the_error_envelope(client: TestClient) -> None:
    response = client.get("/does-not-exist")

    assert response.status_code == 404
    body = response.json()
    assert body["error"]["code"] == "NOT_FOUND"
    assert "message" in body["error"]


def test_unhandled_exception_uses_the_error_envelope(
    grobid_stub: GrobidStub, caplog: object
) -> None:
    app = make_app(grobid_stub)

    @app.get("/boom")
    async def boom() -> None:
        raise RuntimeError("secret detail")

    with caplog.at_level(logging.ERROR, logger="inference.main"):  # type: ignore[attr-defined]
        with TestClient(app, raise_server_exceptions=False) as client:
            response = client.get("/boom")

    assert response.status_code == 500
    assert response.json() == {"error": {"code": "SERVER_ERROR", "message": "Terjadi kesalahan."}}
    assert "secret detail" not in response.text
