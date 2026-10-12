import httpx

from app.config import GROBID_TIMEOUT_SECONDS, GROBID_URL


class GrobidUnavailableError(RuntimeError):
    pass


async def check_grobid() -> bool:
    try:
        async with httpx.AsyncClient(timeout=5.0) as client:
            response = await client.get(f"{GROBID_URL}/api/isalive")
            return response.status_code == 200
    except httpx.HTTPError:
        return False


async def extract_tei(pdf_bytes: bytes, filename: str) -> str:
    url = f"{GROBID_URL}/api/processFulltextDocument"
    files = {"input": (filename, pdf_bytes, "application/pdf")}
    data = {
        "teiCoordinates": ["ref", "biblStruct"]
    }

    

    try:
        timeout = httpx.Timeout(GROBID_TIMEOUT_SECONDS)
        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(url, files=files, data=data)
    except httpx.HTTPError as exc:
        raise GrobidUnavailableError("GROBID is unavailable") from exc

    if response.status_code != 200:
        if response.status_code >= 500:
            raise GrobidUnavailableError("GROBID is unavailable")
        raise ValueError("GROBID could not process the document")

    tei_xml = response.text
    if not tei_xml.strip():
        raise ValueError("GROBID returned empty TEI XML")
    return tei_xml
