"""``GET /health`` — liveness of the internal service and its capabilities.

Reports only GROBID today; the SBERT slice adds ``sbert`` (Phase 02).
"""

from fastapi import APIRouter, Depends

from inference.grobid import GrobidClient, get_grobid_client
from inference.schemas import HealthData

router = APIRouter()


@router.get("/health", response_model=HealthData)
async def health(grobid: GrobidClient = Depends(get_grobid_client)) -> HealthData:
    grobid_up = await grobid.is_alive()
    return HealthData(
        status="ok" if grobid_up else "degraded",
        grobid="up" if grobid_up else "down",
    )
