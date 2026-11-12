from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from support import (
    FakeSentenceModel,
    GrobidStub,
    make_client,
    make_encoder,
    minimal_pdf_bytes,
)

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.fixture()
def grobid_stub() -> GrobidStub:
    return GrobidStub()


@pytest.fixture()
def sbert_model() -> FakeSentenceModel:
    return FakeSentenceModel()


@pytest.fixture()
def client(grobid_stub: GrobidStub, sbert_model: FakeSentenceModel) -> Iterator[TestClient]:
    with make_client(grobid_stub, encoder=make_encoder(sbert_model)) as test_client:
        yield test_client


@pytest.fixture()
def fulltext_tei() -> str:
    return (FIXTURES / "tei" / "fulltext.tei.xml").read_text(encoding="utf-8")


@pytest.fixture()
def empty_tei() -> str:
    return (FIXTURES / "tei" / "empty.tei.xml").read_text(encoding="utf-8")


@pytest.fixture()
def pdf_bytes() -> bytes:
    return minimal_pdf_bytes()
