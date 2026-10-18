from __future__ import annotations

import json
import xml.etree.ElementTree as ET
from pathlib import Path

import pytest

from inference.grobid import TEIParseError, parse_tei
from inference.grobid.tei import extract_page_sizes, get_doi, get_publication_year, parse_coords
from inference.schemas import ExtractionData

REPO_ROOT = Path(__file__).resolve().parents[2]
TEI_NS = "http://www.tei-c.org/ns/1.0"


def test_parse_coords_skips_missing_and_malformed_values() -> None:
    assert parse_coords(None, {}) == []
    assert parse_coords("", {}) == []
    assert parse_coords("1,10,20", {}) == []
    assert parse_coords("page,1,2,3,4", {}) == []


def test_parse_coords_maps_pages_to_boxes() -> None:
    boxes = parse_coords(
        "1,10.5,20.25,30.0,40.5;2,1,2,3,4",
        {1: (595.0, 842.0), 2: (600.0, 800.0)},
    )

    assert boxes == [
        {
            "page_number": 1,
            "x": 10.5,
            "y": 20.25,
            "width": 30.0,
            "height": 40.5,
            "page_width": 595.0,
            "page_height": 842.0,
            "coordinate_system": "pdf_points_top_left",
            "location_index": 0,
        },
        {
            "page_number": 2,
            "x": 1.0,
            "y": 2.0,
            "width": 3.0,
            "height": 4.0,
            "page_width": 600.0,
            "page_height": 800.0,
            "coordinate_system": "pdf_points_top_left",
            "location_index": 1,
        },
    ]


def test_parse_coords_keeps_unknown_page_sizes_null() -> None:
    boxes = parse_coords("3,1,2,3,4", {})

    assert boxes[0]["page_width"] is None
    assert boxes[0]["page_height"] is None


def test_extract_page_sizes_subtracts_the_upper_left_origin(fulltext_tei: str) -> None:
    root = ET.fromstring(fulltext_tei)

    sizes = extract_page_sizes(root)

    assert sizes[1] == pytest.approx((595.276, 841.89))


def test_extract_page_sizes_returns_empty_without_facsimile() -> None:
    root = ET.fromstring(f'<TEI xmlns="{TEI_NS}"><text/></TEI>')

    assert extract_page_sizes(root) == {}


def test_get_doi_unwraps_doi_org_urls() -> None:
    bibl = ET.fromstring(
        f'<biblStruct xmlns="{TEI_NS}"><idno type="DOI">https://doi.org/10.1234/ABC</idno></biblStruct>'
    )

    assert get_doi(bibl) == "10.1234/ABC"


def test_get_doi_falls_back_to_ptr_targets() -> None:
    bibl = ET.fromstring(
        f'<biblStruct xmlns="{TEI_NS}"><ptr target="https://doi.org/10.5555/xyz"/></biblStruct>'
    )

    assert get_doi(bibl) == "10.5555/xyz"


def test_get_doi_returns_none_without_an_identifier() -> None:
    bibl = ET.fromstring(f'<biblStruct xmlns="{TEI_NS}"><analytic/></biblStruct>')

    assert get_doi(bibl) is None


def test_get_publication_year_reads_when_then_text() -> None:
    bibl = ET.fromstring(
        f'<biblStruct xmlns="{TEI_NS}"><monogr><imprint>'
        '<date type="published" when="2019">Spring 2019</date>'
        "</imprint></monogr></biblStruct>"
    )

    assert get_publication_year(bibl) == 2019


def test_parse_tei_extracts_header_references_and_citations(fulltext_tei: str) -> None:
    parsed = parse_tei(fulltext_tei)

    assert parsed["header"]["title"] == "Sistem Deteksi Referensi Halu"
    assert parsed["header"]["authors"] == ["Budi Santoso"]

    references = parsed["references"]
    assert [reference["reference_index"] for reference in references] == [0, 1]
    # `xml_id` links citations to references internally and never leaves the parser.
    assert "xml_id" not in references[0]
    assert references[1]["doi"] is None

    assert [citation["reference_index"] for citation in parsed["citations"]] == [0, 1, 0]


def test_parse_tei_rejects_malformed_xml() -> None:
    with pytest.raises(TEIParseError):
        parse_tei("<broken")


def test_captured_real_response_matches_the_contract_models() -> None:
    payload = json.loads(
        (REPO_ROOT / "docs" / "inference-extract-response.json").read_text(encoding="utf-8")
    )

    data = ExtractionData.model_validate(payload["data"])

    assert data.references
    assert data.citations
    assert data.references[0].locations
    assert data.references[0].locations[0].coordinate_system == "pdf_points_top_left"
