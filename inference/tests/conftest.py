from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from support import GrobidStub, make_client, minimal_pdf_bytes

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.fixture()
def grobid_stub() -> GrobidStub:
    return GrobidStub()


@pytest.fixture()
def client(grobid_stub: GrobidStub) -> Iterator[TestClient]:
    with make_client(grobid_stub) as test_client:
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
