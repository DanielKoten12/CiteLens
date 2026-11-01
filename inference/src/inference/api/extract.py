"""``POST /v1/extract`` — GROBID extraction of a PDF.

Validation order (documented in ``docs/API_SPEC.md`` §9): media type/extension
(415) → upload size (413) → PDF payload (422) → GROBID availability (503) →
unparseable TEI (422). Failures raise ``HTTPException`` with a structured
detail; the app-level handler renders the standard error envelope.
"""

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile

from inference.config import Settings, get_settings
from inference.grobid import (
    GrobidClient,
    GrobidExtractionError,
    GrobidUnavailableError,
    TEIParseError,
    get_grobid_client,
    parse_tei,
)
from inference.schemas import ExtractResponse, ExtractionData

router = APIRouter()

_PDF_CONTENT_TYPES = frozenset({"application/pdf", "application/octet-stream", ""})

_EXTRACTION_FAILED_MESSAGE = "GROBID could not process the document."


@router.post("/v1/extract", response_model=ExtractResponse)
async def extract(
    file: UploadFile = File(...),
    settings: Settings = Depends(get_settings),
    grobid: GrobidClient = Depends(get_grobid_client),
) -> ExtractResponse:
    filename = file.filename or "document.pdf"
    content_type = (file.content_type or "").lower().split(";")[0].strip()

    if not filename.lower().endswith(".pdf") or content_type not in _PDF_CONTENT_TYPES:
        raise HTTPException(
            status_code=415,
            detail={
                "code": "UNSUPPORTED_MEDIA_TYPE",
                "message": "Format file tidak didukung. Hanya PDF yang diterima.",
                "details": {"file": ["File harus berupa PDF."]},
            },
        )

    # Read one byte past the cap so an oversize upload is detected without
    # buffering the whole body.
    pdf_bytes = await file.read(settings.max_upload_bytes + 1)
    await file.close()

    if len(pdf_bytes) > settings.max_upload_bytes:
        raise HTTPException(
            status_code=413,
            detail={
                "code": "PAYLOAD_TOO_LARGE",
                "message": "Ukuran file melebihi batas yang diizinkan.",
                "details": {"file": [f"Ukuran maksimum {settings.max_upload_mb} MB."]},
            },
        )

    if not pdf_bytes or not pdf_bytes.startswith(b"%PDF-"):
        raise HTTPException(
            status_code=422,
            detail={
                "code": "EXTRACTION_FAILED",
                "message": _EXTRACTION_FAILED_MESSAGE,
                "details": {"file": ["File kosong atau bukan PDF yang valid."]},
            },
        )

    try:
        tei_xml = await grobid.extract_tei(pdf_bytes, filename)
        parsed = parse_tei(tei_xml, pdf_bytes=pdf_bytes)
    except GrobidUnavailableError as exc:
        raise HTTPException(
            status_code=503,
            detail={"code": "EXTRACTION_FAILED", "message": _EXTRACTION_FAILED_MESSAGE},
        ) from exc
    except (GrobidExtractionError, TEIParseError) as exc:
        raise HTTPException(
            status_code=422,
            detail={"code": "EXTRACTION_FAILED", "message": _EXTRACTION_FAILED_MESSAGE},
        ) from exc

    return ExtractResponse(data=ExtractionData.model_validate(parsed))
