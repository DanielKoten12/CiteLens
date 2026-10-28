"""``GET /health`` — liveness of the internal service and its capabilities.

Reports GROBID and SBERT. ``status`` is ``ok`` only when both are up. ``sbert`` is
``up`` once the model is in memory and never triggers a load: the container
healthcheck calls this endpoint with a short timeout and must not wait on a
model download. Set ``SBERT_PRELOAD=true`` to load it at startup; otherwise it
is ``down`` until the first ``/v1/embeddings`` request has loaded it.
"""

from fastapi import APIRouter, Depends

from inference.grobid import GrobidClient, get_grobid_client
from inference.sbert import SbertEncoder, get_sbert_encoder
from inference.schemas import HealthData

router = APIRouter()


@router.get("/health", response_model=HealthData)
async def health(
    grobid: GrobidClient = Depends(get_grobid_client),
    sbert: SbertEncoder = Depends(get_sbert_encoder),
) -> HealthData:
    grobid_up = await grobid.is_alive()
    sbert_up = sbert.is_loaded()
    return HealthData(
        status="ok" if grobid_up and sbert_up else "degraded",
        grobid="up" if grobid_up else "down",
        sbert="up" if sbert_up else "down",
    )
