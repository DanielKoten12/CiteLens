from __future__ import annotations

import json
import xml.etree.ElementTree as ET
from pathlib import Path

import pytest

from inference.grobid import TEIParseError, parse_tei
from inference.grobid.tei import (
    context_snippet,
    extract_page_sizes,
    get_doi,
    get_publication_year,
    parse_coords,
)
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


def test_context_snippet_truncates_at_word_boundaries() -> None:
    text = "prefix " + ("kata " * 40) + " (Koten, 2020) " + ("lanjutan " * 40)
    start = text.index("(Koten")
    before, after = context_snippet(text, start, start + len("(Koten, 2020)"))

    assert before is not None and after is not None
    assert before.startswith("...")
    assert before.rstrip().endswith("kata")
    assert after.lstrip().startswith("lanjutan")
    assert after.endswith("...")


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


def test_parse_tei_ignores_back_matter_headings_wrapped_as_references() -> None:
    tei = f"""
    <TEI xmlns="{TEI_NS}">
      <text>
        <back>
          <div><head>SARAN</head>
            <listBibl>
              <biblStruct xml:id="heading"><analytic><title>Daftar Pustaka</title></analytic></biblStruct>
              <biblStruct xml:id="real">
                <analytic><title>Metode Penelitian</title>
                  <author><persName><surname>Fidiani</surname></persName></author>
                </analytic>
                <monogr><title level="j">Jurnal Riset</title>
                  <imprint><date when="2022">2022</date></imprint>
                </monogr>
              </biblStruct>
            </listBibl>
          </div>
        </back>
      </text>
    </TEI>
    """

    parsed = parse_tei(tei)

    assert len(parsed["references"]) == 1
    assert parsed["references"][0]["title"] == "Metode Penelitian"
    assert parsed["references"][0]["reference_index"] == 0


def test_parse_tei_rejects_long_saran_prose_wrapped_as_reference() -> None:
    tei = f"""
    <TEI xmlns="{TEI_NS}">
      <text>
        <back><div><head>SARAN</head><listBibl>
          <biblStruct xml:id="saran">
            <analytic><title>
              Penelitian selanjutnya dapat mengembangkan kajian ini dengan memperhatikan
              berbagai faktor lain agar hasil analisis dapat digunakan oleh masyarakat.
            </title></analytic>
          </biblStruct>
          <biblStruct xml:id="real">
            <analytic>
              <title>Metode Penelitian</title>
              <author><persName><surname>Fidiani</surname></persName></author>
            </analytic>
            <monogr><imprint><date when="2022">2022</date></imprint></monogr>
          </biblStruct>
        </listBibl></div></back>
      </text>
    </TEI>
    """

    parsed = parse_tei(tei)

    assert len(parsed["references"]) == 1
    assert parsed["references"][0]["title"] == "Metode Penelitian"


def test_parse_tei_merges_split_institutional_reference_fragments() -> None:
    tei = f"""
    <TEI xmlns="{TEI_NS}">
      <facsimile><surface lrx="595" lry="842"/></facsimile>
      <text><back><listBibl>
        <biblStruct xml:id="bsn" coords="1,80,200,200,10">
          <analytic><title>SNI Nomor</title>
            <author><persName><surname>BSN</surname></persName></author>
          </analytic>
          <monogr><imprint><date when="2004">2004</date></imprint></monogr>
        </biblStruct>
        <biblStruct coords="1,80,212,400,10">
          <analytic><title>Tentang Tata Cara Perencanaan Lingkungan Perumahan</title></analytic>
        </biblStruct>
      </listBibl></back></text>
    </TEI>
    """

    parsed = parse_tei(tei)

    assert len(parsed["references"]) == 1
    assert parsed["references"][0]["title"] == "SNI Nomor Tentang Tata Cara Perencanaan Lingkungan Perumahan"
    assert len(parsed["references"][0]["locations"]) == 2


def test_captured_real_response_matches_the_contract_models() -> None:
    payload = json.loads(
        (REPO_ROOT / "docs" / "inference-extract-response.json").read_text(encoding="utf-8")
    )

    data = ExtractionData.model_validate(payload["data"])

    assert data.references
    assert data.citations
    assert data.references[0].locations
    assert data.references[0].locations[0].coordinate_system == "pdf_points_top_left"
