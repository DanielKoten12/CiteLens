from __future__ import annotations

from pathlib import Path

from inference.citations import extract_pdf_citations, merge_citations, segment_bibliography


FIXTURE_DIR = Path(__file__).parent / "fixtures" / "pdfs"


def test_sinfor_pdf_fallback_extracts_parenthetical_citations_with_locations() -> None:
    pdf = (FIXTURE_DIR / "sinfor-vincent.pdf").read_bytes()

    citations = extract_pdf_citations(
        pdf,
        {
            (surname, year)
            for surname, year in [
                ("Riadhi", 2020),
                ("Pelambi", 2016),
                ("Rizal", 2022),
                ("Syaibana", 2022),
            ]
        },
    )

    markers = {citation["citation_marker"] for citation in citations}

    assert any("Riadhi" in marker and "2020" in marker for marker in markers)
    assert any("Pelambi" in marker and "2016" in marker for marker in markers)
    assert any("Rizal" in marker and "Syaibana" in marker for marker in markers)
    assert all(citation["locations"] for citation in citations)


def test_jii_pdf_fallback_extracts_narrative_citations() -> None:
    pdf = (FIXTURE_DIR / "jii-7-2-april-2025.pdf").read_bytes()

    citations = extract_pdf_citations(
        pdf,
        {
            ("Asrul", 2025),
            ("Lubis", 2024),
            ("Maulani", 2024),
        },
    )

    markers = {citation["citation_marker"] for citation in citations}

    assert any("Asrul" in marker and "2025" in marker for marker in markers)
    assert any("Lubis" in marker and "2024" in marker for marker in markers)
    assert any("Maulani" in marker and "2024" in marker for marker in markers)


def test_reference_segmentation_keeps_fidiani_and_sustainability_separate() -> None:
    pdf = (FIXTURE_DIR / "sinfor-vincent.pdf").read_bytes()

    entries = segment_bibliography(pdf)

    fidiani = [entry for entry in entries if "Fidiani" in entry]
    sustainability = [entry for entry in entries if "Sustainability (Switzerland)" in entry]

    assert len(fidiani) == 1
    assert len(sustainability) == 1
    assert fidiani[0] != sustainability[0]


def test_fallback_citation_is_deduplicated_by_bbox_when_offsets_differ() -> None:
    grobid = [
        {
            "citation_text": "(Rizal & Syaibana, 2022)",
            "text_start_offset": 900,
            "text_end_offset": 924,
            "locations": [
                {
                    "page_number": 3,
                    "x": 100,
                    "y": 200,
                    "width": 120,
                    "height": 12,
                }
            ],
        }
    ]
    fallback = [
        {
            "citation_text": "(Rizal & Syaibana, 2022)",
            "text_start_offset": 1200,
            "text_end_offset": 1224,
            "locations": [
                {
                    "page_number": 3,
                    "x": 102,
                    "y": 201,
                    "width": 118,
                    "height": 12,
                }
            ],
        }
    ]

    merged = merge_citations(grobid, fallback)

    assert len(merged) == 1
    assert merged[0]["citation_text"] == "(Rizal & Syaibana, 2022)"
