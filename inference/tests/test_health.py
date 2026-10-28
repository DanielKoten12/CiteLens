from __future__ import annotations

import httpx
from fastapi.testclient import TestClient

from support import GrobidStub, connect_error, make_client, make_encoder


def _loaded_client(grobid_stub: GrobidStub) -> TestClient:
    encoder = make_encoder()
    assert encoder.warm_up()
    return make_client(grobid_stub, encoder=encoder)


def test_health_reports_grobid_and_sbert_up(grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(200))

    with _loaded_client(grobid_stub) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "grobid": "up", "sbert": "up"}


def test_health_reports_grobid_down_on_connection_error(grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", connect_error("/api/isalive"))

    with _loaded_client(grobid_stub) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "degraded", "grobid": "down", "sbert": "up"}


def test_health_reports_grobid_down_on_server_error(grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(500))

    with _loaded_client(grobid_stub) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "degraded", "grobid": "down", "sbert": "up"}


def test_health_never_loads_the_model(grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(200))
    loads: list[str] = []

    from inference.sbert import SbertEncoder
    from support import FakeSentenceModel

    def loader(name: str) -> FakeSentenceModel:
        loads.append(name)
        return FakeSentenceModel()

    with make_client(grobid_stub, encoder=SbertEncoder("test-model", loader=loader)) as client:
        response = client.get("/health")

    assert response.json() == {"status": "degraded", "grobid": "up", "sbert": "down"}
    assert loads == []


def test_health_reports_sbert_down_when_the_model_cannot_load(grobid_stub: GrobidStub) -> None:
    grobid_stub.respond("/api/isalive", httpx.Response(200))
    encoder = make_encoder(OSError("no weights"))
    assert encoder.warm_up() is False

    with make_client(grobid_stub, encoder=encoder) as client:
        response = client.get("/health")

    assert response.json() == {"status": "degraded", "grobid": "up", "sbert": "down"}
