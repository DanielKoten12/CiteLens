from __future__ import annotations

import httpx
from fastapi.testclient import TestClient

from support import GrobidStub, connect_error


def test_health_reports_grobid_up(client: TestClient, grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(200))

    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["grobid"] == "up"
    # `sbert` arrives with the Phase 02 slice; tolerate the future key.
    assert set(body) <= {"status", "grobid", "sbert"}


def test_health_reports_grobid_down_on_connection_error(
    client: TestClient, grobid_stub: GrobidStub
) -> None:
    grobid_stub.respond("/api/isalive", connect_error("/api/isalive"))

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "degraded", "grobid": "down"}


def test_health_reports_grobid_down_on_server_error(
    client: TestClient, grobid_stub: GrobidStub
) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(500))

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "degraded", "grobid": "down"}
