"""Regression tests on two real PDFs. Expected values are ground truth read from the
documents themselves, not outputs copied from the code under test."""

import re
from pathlib import Path

import pytest

from inference.bibliography import align_references, fields_from_text, segment_references
from inference.citations import extract_pdf_citations, merge_citations
from inference.pdf_text import PdfText

FIXTURES = Path(__file__).parent / "fixtures" / "pdfs"


def _load(name: str) -> PdfText:
    return PdfText.from_bytes((FIXTURES / name).read_bytes())


def _author_years(doc: PdfText) -> set[tuple[str, int]]:
    pairs = set()
    for segment in segment_references(doc):
        fields = fields_from_text(segment.text)
        if fields["authors"] and fields["publication_year"]:
            first = re.split(r",|;| and | dan | & ", fields["authors"], maxsplit=1, flags=re.I)[0]
            pairs.add((first.split()[-1].strip(".,"), int(fields["publication_year"])))
    return pairs


@pytest.fixture(scope="module")
def sinfor() -> PdfText:
    return _load("sinfor-vincent.pdf")


@pytest.fixture(scope="module")
def jii() -> PdfText:
    return _load("jii-7-2-april-2025.pdf")


def _markers(doc: PdfText) -> list[str]:
    return [c["citation_text"] for c in extract_pdf_citations(doc, _author_years(doc))]


# ----------------------------------------------------------------- bibliography
def test_sinfor_has_15_entries_and_fidiani_is_not_merged(sinfor):
    starts = [s.text.split(".")[0].split(",")[0].split("(")[0].strip() for s in segment_references(sinfor)]
    assert len(starts) == 15
    assert "Fidiani" in starts and "Sustainability" in starts
    fidiani = next(s for s in segment_references(sinfor) if s.text.startswith("Fidiani"))
    assert "Sustainability" not in fidiani.text


def test_sinfor_bsn_is_one_entry_covering_three_lines(sinfor):
    bsn = [s for s in segment_references(sinfor) if s.text.startswith("BSN.")]
    assert len(bsn) == 1 and len(bsn[0].locations) == 3


def test_jii_has_25_entries_and_no_running_header(jii):
    segments = segment_references(jii)
    assert len(segments) == 25
    assert not any("Vol. 07" in s.text or "e-ISSN" in s.text for s in segments)
    assert not any(s.text.startswith("Kutipan") for s in segments)
    amira = next(s for s in segments if s.text.startswith("Amira"))
    assert "10.61722/jinu.v1i4.1888" in amira.text


def test_cyrillic_homoglyph_is_repaired(jii):
    assert any(s.text.startswith("Shao, X.") for s in segment_references(jii))


# -------------------------------------------------------------------- citations
SINFOR_EXPECTED = {
    "(Dwianti et al., 2021)", "(Fidiani, 2020)", "(Rizal & Syaibana, 2022)",
    "(Badan Pusat Statistik Kota Salatiga, 2019)", "(Achyanadia, 2016)", "(Sutanto et al., 2019)",
    "Mukhlis dkk (2019)", "(Mukhlis & Musyawarah, 2019)", "(Ramadhana & Prakoso, 2018)",
    "(Wijayanti, 2017)", "(Riadhi et al., 2020)", "(Pelambi et al., 2016)", "(BSN, 2004)",
}


def test_sinfor_citations_complete_and_exact(sinfor):
    markers = _markers(sinfor)
    assert SINFOR_EXPECTED <= set(markers)
    assert markers.count("(Rizal & Syaibana, 2022)") == 2
    assert len(markers) == 15


def test_narrative_span_has_no_leading_words(jii):
    markers = _markers(jii)
    for bad in ("studi Asrul", "Menurut", "oleh Maulani", "Layanan", "Perdagangan:"):
        assert not any(bad in m for m in markers)
    assert {"Asrul et al. (2025)", "Lubis (2024)", "Maulani et al. (2024)", "Syira et al. (2023)"} <= set(markers)


def test_jii_table_rows_detected_as_cells_not_rows(jii):
    cits = extract_pdf_citations(jii, _author_years(jii))
    table = [c for c in cits if c["locations"][0]["page_number"] in (8, 9) and c["context_before"] is None]
    names = {c["citation_text"] for c in table}
    assert {"Maulani et al. (2024)", "Oktatriani et al. (2023)", "Amira (2024)", "Asrul et al. (2025)"} <= names
    for citation in table:  # a cell, never the whole table row
        assert all(loc["width"] < 0.3 * loc["page_width"] for loc in citation["locations"])


def test_every_citation_has_a_sentence_quote(jii, sinfor):
    for doc in (jii, sinfor):
        for c in extract_pdf_citations(doc, _author_years(doc)):
            quote = (c["context_before"] or "") + c["citation_text"] + (c["context_after"] or "")
            assert quote.strip() == quote and len(quote) >= len(c["citation_text"])
            assert c["locations"], c["citation_text"]


def test_quote_is_full_sentence(jii):
    cit = next(c for c in extract_pdf_citations(jii, _author_years(jii)) if c["citation_text"] == "Lubis (2024)" and c["context_before"])
    quote = cit["context_before"] + cit["citation_text"] + cit["context_after"]
    assert quote == (
        "Inovasi Produk dan Layanan: Lubis (2024) menekankan bahwa analisis tren pasar berbasis Big Data "
        "memungkinkan perusahaan merancang produk yang sesuai dengan kebutuhan pelanggan."
    )


def test_lone_citation_sentence_joins_previous_sentence(sinfor):
    cit = next(c for c in extract_pdf_citations(sinfor, _author_years(sinfor)) if c["citation_text"] == "(Riadhi et al., 2020)")
    quote = cit["context_before"] + cit["citation_text"] + cit["context_after"]
    assert quote.startswith("Dengan menghitung analisis tetangga terdekat") and quote.endswith("(Riadhi et al., 2020).")


def test_citation_without_space_after_period(sinfor):
    cit = next(c for c in extract_pdf_citations(sinfor, _author_years(sinfor)) if c["citation_text"] == "Mukhlis dkk (2019)")
    assert (cit["context_before"] or "") == ""  # sentence starts at the name, not in the previous sentence


def test_geometry_is_per_line_and_inside_page(jii):
    for c in extract_pdf_citations(jii, _author_years(jii)):
        for loc in c["locations"]:
            assert 0 <= loc["x"] and loc["x"] + loc["width"] <= loc["page_width"] + 1
            assert loc["height"] < 30


# ---------------------------------------------------------------------- merging
def test_merge_replaces_overbroad_grobid_box_and_keeps_reference_index(jii):
    mine = extract_pdf_citations(jii, _author_years(jii))
    asrul = next(c for c in mine if c["citation_text"] == "Asrul et al. (2025)" and c["context_before"])
    loc = dict(asrul["locations"][0])
    loc.update(x=loc["x"] - 40, width=loc["width"] + 40)  # GROBID box that also covers "studi"
    grobid = {"citation_text": "studi Asrul et al. (2025)", "citation_marker": "studi Asrul et al. (2025)",
              "reference_index": 3, "locations": [loc], "text_start_offset": 10, "text_end_offset": 40,
              "context_before": None, "context_after": None, "occurrence_index": 0}
    merged = merge_citations([grobid], mine, jii)
    hit = [c for c in merged if c["citation_text"] == "Asrul et al. (2025)" and c["context_before"]]
    assert len(hit) == 1 and hit[0]["reference_index"] == 3
    assert not any(c["citation_text"].startswith("studi") for c in merged)


# ------------------------------------------------------------------- alignment
def test_alignment_drops_header_and_splits_merged_entries(sinfor):
    segs = segment_references(sinfor)
    text = {s.text.split(" ")[0]: s.text for s in segs}
    fid, sus = text["Fidiani,"], next(s.text for s in segs if s.text.startswith("Sustainability"))
    candidates = [
        {"raw_text": fid + " " + sus, "authors": "A Fidiani", "doi": None, "xml_id": "f1", "locations": []},
        {"raw_text": "Jurnal Sistem Informasi dan Informatika (Simika) Vol. 6 No. 1 P-ISSN", "authors": None, "doi": None, "xml_id": "h", "locations": []},
    ] + [{"raw_text": s.text, "authors": "x", "doi": None, "xml_id": f"s{i}", "locations": []}
         for i, s in enumerate(segs) if not s.text.startswith(("Fidiani", "Sustainability"))]
    refs = align_references(candidates, segs)
    assert len(refs) == 15
    assert not any("P-ISSN" in r["raw_text"] for r in refs)
    fidiani = next(r for r in refs if r["raw_text"].startswith("Fidiani"))
    assert fidiani["xml_id"] == "f1" and "Sustainability" not in fidiani["raw_text"]
    assert [r["reference_index"] for r in refs] == list(range(15))


def test_alignment_falls_back_to_grobid_when_it_is_unreliable(sinfor):
    garbage = [{"raw_text": "completely unrelated text", "doi": None, "locations": []}] * 6
    assert align_references(garbage, segment_references(sinfor)) is garbage
