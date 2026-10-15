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
