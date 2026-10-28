from __future__ import annotations

import logging

from fastapi.testclient import TestClient

from support import FakeSentenceModel, GrobidStub, make_client, make_encoder

TEXTS = ["Deep learning", "Sistem Deteksi Plagiarisme"]


def test_embeddings_returns_one_vector_per_text_in_order(
    client: TestClient, sbert_model: FakeSentenceModel
) -> None:
    response = client.post("/v1/embeddings", json={"texts": TEXTS, "model": "sbert"})

    assert response.status_code == 200
    data = response.json()["data"]
    assert data["model"] == "sbert"
    assert data["dimensions"] == 4
    assert len(data["embeddings"]) == len(TEXTS)
    assert all(len(vector) == data["dimensions"] for vector in data["embeddings"])
    # Order is preserved: the fake derives the first component from text length.
    assert [vector[0] for vector in data["embeddings"]] == [float(len(text)) for text in TEXTS]
    assert sbert_model.calls[0][0] == TEXTS


def test_embeddings_model_defaults_to_sbert(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": ["Deep learning"]})

    assert response.status_code == 200
    assert response.json()["data"]["model"] == "sbert"


def test_embeddings_asks_the_model_for_normalised_batched_vectors(
    grobid_stub: GrobidStub,
) -> None:
    model = FakeSentenceModel()

    with make_client(grobid_stub, encoder=make_encoder(model, batch_size=8)) as client:
        client.post("/v1/embeddings", json={"texts": TEXTS})

    _, kwargs = model.calls[0]
    assert kwargs["batch_size"] == 8
    assert kwargs["normalize_embeddings"] is True


def test_embeddings_equal_texts_embed_identically(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": ["Deep learning", "Deep learning"]})

    vectors = response.json()["data"]["embeddings"]
    assert vectors[0] == vectors[1]


def test_embeddings_loads_the_model_once_across_requests(grobid_stub: GrobidStub) -> None:
    loads: list[str] = []
    model = FakeSentenceModel()

    def loader(name: str) -> FakeSentenceModel:
        loads.append(name)
        return model

    from inference.sbert import SbertEncoder

    encoder = SbertEncoder(model_name="test-model", loader=loader)

    with make_client(grobid_stub, encoder=encoder) as client:
        client.post("/v1/embeddings", json={"texts": ["a"]})
        client.post("/v1/embeddings", json={"texts": ["b"]})

    assert loads == ["test-model"]


def test_embeddings_requires_texts(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert "texts" in body["error"]["details"]


def test_embeddings_rejects_an_empty_texts_list(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": []})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert "texts" in body["error"]["details"]


def test_embeddings_rejects_a_blank_text(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": ["Deep learning", "   "]})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert "texts" in body["error"]["details"]


def test_embeddings_rejects_non_string_texts(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": [123]})

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "VALIDATION_ERROR"


def test_embeddings_rejects_an_unknown_model(client: TestClient) -> None:
    response = client.post("/v1/embeddings", json={"texts": ["Deep learning"], "model": "gpt"})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert "model" in body["error"]["details"]


def test_embeddings_rejects_a_batch_over_the_configured_cap(grobid_stub: GrobidStub) -> None:
    with make_client(grobid_stub, embedding_max_texts=2) as client:
        response = client.post("/v1/embeddings", json={"texts": ["a", "b", "c"]})

    assert response.status_code == 422
    body = response.json()
    assert body["error"]["code"] == "VALIDATION_ERROR"
    assert body["error"]["details"]["texts"] == ["Maksimum 2 teks per permintaan."]


def test_embeddings_returns_503_when_the_model_cannot_load(
    grobid_stub: GrobidStub, caplog: object
) -> None:
    encoder = make_encoder(OSError("secret path /models/x"))

    with caplog.at_level(logging.ERROR, logger="inference.sbert.encoder"):  # type: ignore[attr-defined]
        with make_client(grobid_stub, encoder=encoder) as client:
            response = client.post("/v1/embeddings", json={"texts": TEXTS})

    assert response.status_code == 503
    assert response.json() == {
        "error": {"code": "INFERENCE_FAILED", "message": "SBERT model is unavailable."}
    }
    assert "secret path" not in response.text


def test_embeddings_returns_503_when_encoding_fails(grobid_stub: GrobidStub) -> None:
    model = FakeSentenceModel(failure=RuntimeError("CUDA out of memory"))

    with make_client(grobid_stub, encoder=make_encoder(model)) as client:
        response = client.post("/v1/embeddings", json={"texts": TEXTS})

    assert response.status_code == 503
    body = response.json()
    assert body["error"]["code"] == "INFERENCE_FAILED"
    assert "CUDA" not in response.text


def test_embeddings_recovers_after_a_failed_model_load(grobid_stub: GrobidStub) -> None:
    attempts = {"count": 0}
    model = FakeSentenceModel()

    def flaky_loader(name: str) -> FakeSentenceModel:
        attempts["count"] += 1
        if attempts["count"] == 1:
            raise OSError("download interrupted")
        return model

    from inference.sbert import SbertEncoder

    encoder = SbertEncoder(model_name="test-model", loader=flaky_loader)

    with make_client(grobid_stub, encoder=encoder) as client:
        first = client.post("/v1/embeddings", json={"texts": TEXTS})
        second = client.post("/v1/embeddings", json={"texts": TEXTS})

    assert first.status_code == 503
    assert second.status_code == 200


def test_embeddings_cuts_overlong_texts_instead_of_failing(grobid_stub: GrobidStub) -> None:
    from inference.sbert import SbertEncoder

    model = FakeSentenceModel()
    encoder = SbertEncoder(model_name="test-model", loader=lambda name: model, max_text_chars=10)

    with make_client(grobid_stub, encoder=encoder) as client:
        response = client.post("/v1/embeddings", json={"texts": ["x" * 5000]})

    assert response.status_code == 200
    assert model.calls[0][0] == ["x" * 10]
