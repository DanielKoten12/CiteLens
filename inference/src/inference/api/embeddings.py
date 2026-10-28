"""``POST /v1/embeddings`` — SBERT sentence embeddings.

Validation order (documented in ``docs/API_SPEC.md`` §9): request shape
(422 ``VALIDATION_ERROR``: missing/empty/blank ``texts``, unknown ``model``) →
batch size cap (422) → model availability (503 ``INFERENCE_FAILED``). Failures
raise ``HTTPException`` with a structured detail; the app-level handler renders
the standard error envelope.
"""

from fastapi import APIRouter, Depends, HTTPException
from fastapi.concurrency import run_in_threadpool

from inference.config import Settings, get_settings
from inference.sbert import SbertEncoder, SbertUnavailableError, get_sbert_encoder
from inference.schemas import EmbeddingData, EmbeddingRequest, EmbeddingResponse

router = APIRouter()

_INFERENCE_FAILED_MESSAGE = "SBERT model is unavailable."


@router.post("/v1/embeddings", response_model=EmbeddingResponse)
async def embeddings(
    payload: EmbeddingRequest,
    settings: Settings = Depends(get_settings),
    encoder: SbertEncoder = Depends(get_sbert_encoder),
) -> EmbeddingResponse:
    if len(payload.texts) > settings.embedding_max_texts:
        raise HTTPException(
            status_code=422,
            detail={
                "code": "VALIDATION_ERROR",
                "message": "Data permintaan tidak valid.",
                "details": {
                    "texts": [f"Maksimum {settings.embedding_max_texts} teks per permintaan."]
                },
            },
        )

    try:
        vectors = await run_in_threadpool(encoder.encode, payload.texts)
    except SbertUnavailableError as exc:
        raise HTTPException(
            status_code=503,
            detail={"code": "INFERENCE_FAILED", "message": _INFERENCE_FAILED_MESSAGE},
        ) from exc

    return EmbeddingResponse(
        data=EmbeddingData(
            model=payload.model,
            dimensions=len(vectors[0]),
            embeddings=vectors,
        )
    )
