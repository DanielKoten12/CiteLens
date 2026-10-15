from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, File, HTTPException, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from inference.config import Settings, get_settings
from inference.grobid import (
    GrobidClient,
    GrobidExtractionError,
    GrobidUnavailableError,
    TEIParseError,
    get_grobid_client,
    parse_tei,
)
from inference.schemas import ExtractResponse, HealthData


@asynccontextmanager
async def lifespan(app: FastAPI):
    yield


app = FastAPI(
    title="Dafpus Cek Document Extraction API",
    description=(
        "Internal-only service for extracting document metadata, bibliography references, "
        "in-text citations, and PDF locations through GROBID."
    ),
    version="1.1.0",
    lifespan=lifespan,
)


def error_response(code: str, message: str, status_code: int, details: dict[str, Any] | None = None):
    error: dict[str, Any] = {"code": code, "message": message}
    if details:
        error["details"] = details
    return JSONResponse(status_code=status_code, content={"error": error})


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    detail = exc.detail
    if isinstance(detail, dict) and "code" in detail and "message" in detail:
        return error_response(
            str(detail["code"]),
            str(detail["message"]),
            exc.status_code,
            detail.get("details"),
        )
    defaults = {
        400: ("BAD_REQUEST", "Permintaan tidak valid."),
        404: ("NOT_FOUND", "Endpoint tidak ditemukan."),
        413: ("PAYLOAD_TOO_LARGE", "Ukuran file melewati batas yang diizinkan."),
        415: ("UNSUPPORTED_MEDIA_TYPE", "Hanya file PDF yang diterima."),
        422: ("VALIDATION_ERROR", "Data permintaan tidak valid."),
        503: ("INFERENCE_UNAVAILABLE", "Layanan ekstraksi tidak tersedia."),
    }
    code, fallback_message = defaults.get(exc.status_code, ("SERVER_ERROR", "Terjadi kesalahan."))
    message = detail if isinstance(detail, str) else fallback_message
    return error_response(code, message, exc.status_code)


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    details: dict[str, list[str]] = {}
    for item in exc.errors():
        location = item.get("loc", ())
        field = str(location[-1]) if location else "request"
        details.setdefault(field, []).append(str(item.get("msg", "Nilai tidak valid.")))
    return error_response("VALIDATION_ERROR", "Data permintaan tidak valid.", 422, details)


@app.get("/health", response_model=HealthData)
async def health(grobid: GrobidClient = Depends(get_grobid_client)):
    grobid_up = await grobid.is_alive()
    return {
        "status": "ok" if grobid_up else "degraded",
        "grobid": "up" if grobid_up else "down",
    }


@app.post("/v1/extract", response_model=ExtractResponse)
async def extract(
    file: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    grobid: GrobidClient = Depends(get_grobid_client),
):
    filename = file.filename or "document.pdf"
    content_type = (file.content_type or "").lower().split(";")[0].strip()

    if not filename.lower().endswith(".pdf") or content_type not in ("application/pdf", "application/octet-stream", ""):
        return error_response(
            "UNSUPPORTED_MEDIA_TYPE",
            "Format file tidak didukung. Hanya PDF yang diterima.",
            415,
            {"file": ["File harus berupa PDF."]},
        )

    pdf_bytes = await file.read(settings.max_upload_bytes + 1)
    await file.close()
    if len(pdf_bytes) > settings.max_upload_bytes:
        return error_response(
            "PAYLOAD_TOO_LARGE",
            "Ukuran file melebihi batas yang diizinkan.",
            413,
            {"file": [f"Ukuran maksimum {settings.max_upload_mb} MB."]},
        )
    if not pdf_bytes or not pdf_bytes.startswith(b"%PDF-"):
        return error_response(
            "EXTRACTION_FAILED",
            "GROBID could not process the document.",
            422,
            {"file": ["File kosong atau bukan PDF yang valid."]},
        )

    try:
        tei_xml = await grobid.extract_tei(pdf_bytes, filename)
        parsed = parse_tei(tei_xml)
    except GrobidUnavailableError:
        return error_response(
            "EXTRACTION_FAILED",
            "GROBID could not process the document.",
            503,
        )
    except (GrobidExtractionError, TEIParseError):
        return error_response(
            "EXTRACTION_FAILED",
            "GROBID could not process the document.",
            422,
        )

    return {"data": parsed}
