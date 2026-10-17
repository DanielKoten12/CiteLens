"""FastAPI application factory for the internal inference service.

Wiring only: the exception handlers that render the standard error envelope
(`docs/API_SPEC.md` §2.5) and the capability routers. Capability code lives in
``inference.grobid`` and will grow ``inference.sbert`` for embeddings.
"""

from __future__ import annotations

import logging
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from inference.api import extract, health

logger = logging.getLogger(__name__)

_DEFAULT_ERRORS: dict[int, tuple[str, str]] = {
    400: ("BAD_REQUEST", "Permintaan tidak valid."),
    404: ("NOT_FOUND", "Endpoint tidak ditemukan."),
    413: ("PAYLOAD_TOO_LARGE", "Ukuran file melewati batas yang diizinkan."),
    415: ("UNSUPPORTED_MEDIA_TYPE", "Hanya file PDF yang diterima."),
    422: ("VALIDATION_ERROR", "Data permintaan tidak valid."),
    503: ("INFERENCE_UNAVAILABLE", "Layanan ekstraksi tidak tersedia."),
}


def error_response(
    code: str,
    message: str,
    status_code: int,
    details: dict[str, Any] | None = None,
) -> JSONResponse:
    error: dict[str, Any] = {"code": code, "message": message}
    if details:
        error["details"] = details
    return JSONResponse(status_code=status_code, content={"error": error})


async def http_exception_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
    """Render every HTTPException (explicit raises and framework 404s) as the envelope.

    Registered on ``starlette.exceptions.HTTPException`` so Starlette's own
    404 (raised with its parent class) is caught too; a FastAPI-subclass-keyed
    handler would never match it.
    """

    detail = exc.detail
    if isinstance(detail, dict) and "code" in detail and "message" in detail:
        return error_response(
            str(detail["code"]),
            str(detail["message"]),
            exc.status_code,
            detail.get("details"),
        )

    code, fallback_message = _DEFAULT_ERRORS.get(exc.status_code, ("SERVER_ERROR", "Terjadi kesalahan."))
    message = detail if isinstance(detail, str) else fallback_message
    return error_response(code, message, exc.status_code)


async def validation_exception_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    details: dict[str, list[str]] = {}
    for item in exc.errors():
        location = item.get("loc", ())
        field = str(location[-1]) if location else "request"
        details.setdefault(field, []).append(str(item.get("msg", "Nilai tidak valid.")))
    return error_response("VALIDATION_ERROR", "Data permintaan tidak valid.", 422, details)


async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    """Never leak stack traces or paths; the envelope carries a safe message only."""

    logger.error("Unhandled inference service error", exc_info=exc)
    return error_response("SERVER_ERROR", "Terjadi kesalahan.", 500)


def create_app() -> FastAPI:
    app = FastAPI(
        title="Dafpus Cek Document Extraction API",
        description=(
            "Internal-only service for extracting document metadata, bibliography references, "
            "in-text citations, and PDF locations through GROBID."
        ),
        version="1.1.0",
    )

    app.add_exception_handler(StarletteHTTPException, http_exception_handler)
    app.add_exception_handler(RequestValidationError, validation_exception_handler)
    app.add_exception_handler(Exception, unhandled_exception_handler)

    app.include_router(health.router)
    app.include_router(extract.router)

    return app


app = create_app()
