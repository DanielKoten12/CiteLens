from __future__ import annotations

import asyncio
import os
from pathlib import Path

import pytest

from inference.grobid import GrobidClient, parse_tei


@pytest.mark.integration
def test_real_grobid_extraction() -> None:
    """Opt-in end-to-end check; requires a live GROBID and a real paper PDF.

    Run with:
        GROBID_INTEGRATION_URL=http://localhost:8070 \
        GROBID_INTEGRATION_PDF=/path/to/paper.pdf \
        uv run pytest -m integration
    """

    base_url = os.getenv("GROBID_INTEGRATION_URL")
    pdf_path = os.getenv("GROBID_INTEGRATION_PDF")
    if not base_url or not pdf_path:
        pytest.skip("set GROBID_INTEGRATION_URL and GROBID_INTEGRATION_PDF to run")

    path = Path(pdf_path)
    tei_xml = asyncio.run(
        GrobidClient(base_url=base_url).extract_tei(path.read_bytes(), path.name)
    )
    parsed = parse_tei(tei_xml)

    assert parsed["header"]["title"] or parsed["references"]
    for reference in parsed["references"]:
        for location in reference["locations"]:
            assert location["coordinate_system"] == "pdf_points_top_left"
            assert location["page_number"] >= 1
