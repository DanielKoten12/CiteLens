from __future__ import annotations

import httpx
import pytest
from fastapi.testclient import TestClient

from support import GrobidStub, connect_error, read_timeout

EXTRACTION_FAILED = {
    "error": {
        "code": "EXTRACTION_FAILED",
        "message": "GROBID could not process the document.",
    }
}


def test_extract_returns_the_documented_payload(
    client: TestClient, grobid_stub: GrobidStub, fulltext_tei: str, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=fulltext_tei))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 200
    body = response.json()
    assert set(body) == {"data"}
    data = body["data"]
    assert set(data) == {"header", "references", "citations"}

    assert data["header"] == {
        "title": "Sistem Deteksi Referensi Halu",
        "authors": ["Budi Santoso"],
        "abstract": "Abstrak penelitian tentang sitasi halu.",
        "publication_year": None,
        "doi": "10.1234/dafpus.2024.001",
    }

    references = data["references"]
    assert [reference["reference_index"] for reference in references] == [0, 1]
    first, second = references

    assert first["title"] == "Metode Deteksi Sitasi"
    assert first["authors"] == "Budi Santoso"
    assert first["publication_name"] == "Jurnal Ilmu Komputer"
    assert first["publication_year"] == 2020
    assert first["doi"] == "10.1000/xyz123"
    assert first["text_start_offset"] == 98
    assert first["text_end_offset"] == 173
    assert first["locations"] == [
        {
            "page_number": 2,
            "x": 72.0,
            "y": 500.0,
            "width": 451.2,
            "height": 24.0,
            "page_width": 595.276,
            "page_height": 841.89,
            "coordinate_system": "pdf_points_top_left",
            "location_index": 0,
        }
    ]

    # GROBID cannot always parse a field; the contract keeps them `null`.
    assert second["authors"] is None
    assert second["doi"] is None
    assert second["publication_year"] == 2019

    citations = data["citations"]
    assert [citation["occurrence_index"] for citation in citations] == [0, 1, 2]
    assert [citation["reference_index"] for citation in citations] == [0, 1, 0]
    assert citations[0]["citation_text"] == "(Santoso, 2020)"
    assert citations[0]["citation_marker"] == "(Santoso, 2020)"
    assert citations[0]["text_start_offset"] == 13
    assert citations[0]["text_end_offset"] == 28
    assert citations[0]["locations"][0]["page_number"] == 1
    assert citations[1]["citation_text"] == "(Lee & Tan, 2019)"
    assert citations[2]["locations"][0]["page_number"] == 2


def test_extract_sends_ref_and_biblstruct_coordinates(
    client: TestClient, grobid_stub: GrobidStub, fulltext_tei: str, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=fulltext_tei))

    client.post("/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")})

    assert len(grobid_stub.requests) == 1
    request = grobid_stub.requests[0]
    assert request.url.path == "/api/processFulltextDocument"
    assert request.headers["content-type"].startswith("multipart/form-data")

    body = request.content
    # `teiCoordinates` must be sent once per value; a joined value disables coordinates.
    assert body.count(b'name="teiCoordinates"') == 2
    assert b"\r\n\r\nref\r\n" in body
    assert b"\r\n\r\nbiblStruct\r\n" in body
    assert b'filename="paper.pdf"' in body


def test_extract_handles_a_document_without_bibliography(
    client: TestClient, grobid_stub: GrobidStub, empty_tei: str, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=empty_tei))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 200
    data = response.json()["data"]
    assert data["header"]["title"] == "Dokumen tanpa daftar pustaka"
    assert data["references"] == []
    assert data["citations"] == []


@pytest.mark.parametrize(
    "failure",
    [connect_error(), read_timeout(), httpx.Response(500, text="unavailable")],
    ids=["connection-error", "read-timeout", "server-error"],
)
def test_extract_returns_503_when_grobid_is_unavailable(
    client: TestClient, grobid_stub: GrobidStub, pdf_bytes: bytes, failure: httpx.Response | Exception
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", failure)

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 503
    assert response.json() == EXTRACTION_FAILED


def test_extract_returns_422_when_grobid_rejects_the_document(
    client: TestClient, grobid_stub: GrobidStub, pdf_bytes: bytes
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(400, text="Bad Request"))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 422
    assert response.json() == EXTRACTION_FAILED


@pytest.mark.parametrize(
    "tei_xml",
    ["", "   ", "<not-tei", "plain text, not XML"],
    ids=["empty", "blank", "truncated", "not-xml"],
)
def test_extract_returns_422_when_the_tei_is_unusable(
    client: TestClient, grobid_stub: GrobidStub, pdf_bytes: bytes, tei_xml: str
) -> None:
    grobid_stub.respond("/api/processFulltextDocument", httpx.Response(200, text=tei_xml))

    response = client.post(
        "/v1/extract", files={"file": ("paper.pdf", pdf_bytes, "application/pdf")}
    )

    assert response.status_code == 422
    assert response.json() == EXTRACTION_FAILED
