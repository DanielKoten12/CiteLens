"""In-text citation extraction on top of :class:`inference.pdf_text.PdfText`.

Public API kept from the previous module: ``extract_pdf_citations``,
``merge_citations``, ``citations_overlap``, ``segment_bibliography``.

Design rules (each one answers a bug seen on real PDFs):

* A citation span is exactly the marker: ``(Rizal & Syaibana, 2022)`` or
  ``Asrul et al. (2025)``. Words before it ("studi", "Menurut", "oleh",
  "Layanan:") are never part of the span. Narrative citations are anchored on a
  *known bibliography surname*, never on "any capitalised word".
* Bounding boxes come from the glyphs of the span (one rectangle per visual
  line), so a table cell never highlights the whole row.
* ``context_before + citation_text + context_after`` is always the complete
  sentence, first word up to the closing full stop.
* Offsets of two different texts are never compared; overlap is geometric.
"""

from __future__ import annotations

import re
import unicodedata
from typing import Any, Iterable

from inference.pdf_text import PdfText

YEAR = r"(?:19|20)\d{2}[a-z]?"
_YEAR_RE = re.compile(r"(?<![\d])(" + YEAR + r")(?![\d])")
_PAREN_RE = re.compile(r"\(([^()\n]{1,240})\)")
_NUMERIC_RE = re.compile(r"\[\s*(\d{1,3}(?:\s*[,;–—-]\s*\d{1,3})*)\s*\]")

_CAP = r"[A-ZÀ-Ý][\w'’\-]*"
_CONNECTOR = r"(?:&|dan|and)"
_ET_AL = r"(?:et\s*al\.?|dkk\.?|dll\.?)"
# "Koten", "Koten & Tani", "LeCun et al.", "Badan Pusat Statistik"
_STRICT_NAME = re.compile(
    rf"^{_CAP}(?:\s+{_CAP}){{0,3}}(?:\s+{_ET_AL})?(?:\s*{_CONNECTOR}\s+{_CAP}(?:\s+{_CAP}){{0,2}})?$"
)
_LEADING_NOISE = re.compile(r"^(?:see|lihat|cf\.?|misalnya|e\.g\.,?|i\.e\.,?|dalam|menurut|dikutip dari)\s+", re.IGNORECASE)
_STOP_FIRST_WORDS = {
    "gambar", "tabel", "gbr", "bab", "persamaan", "rumus", "vol", "no", "hal", "halaman",
    "page", "figure", "table", "fig", "sumber", "source", "pasal", "uu", "tahun", "ayat",
    "bagian", "lihat", "see", "formula", "grafik", "diagram", "lampiran",
}


# ------------------------------------------------------------------ helpers
def _norm_name(value: str) -> str:
    """Casefolded ASCII letters only: 'Shaо' (Cyrillic о) == 'shao', 'Müller' == 'muller'."""
    value = unicodedata.normalize("NFKD", value)
    value = "".join(c for c in value if not unicodedata.combining(c))
    return re.sub(r"[^a-z]", "", value.casefold())


def _known_surnames(author_years: Iterable[tuple[str, int]]) -> dict[str, set[int]]:
    known: dict[str, set[int]] = {}
    for surname, year in author_years:
        key = _norm_name(surname)
        if len(key) >= 2:
            known.setdefault(key, set()).add(int(year))
    return known


def _name_tokens(text: str) -> list[str]:
    return [_norm_name(t) for t in re.findall(r"[A-Za-zÀ-ÿ][\w'’\-]*", text) if len(_norm_name(t)) >= 2]


def _segment_accepted(segment: str, known: dict[str, set[int]]) -> bool:
    """A ``;``-separated part of a parenthetical group: ``Name(s), year[, p. 5]``."""
    segment = _LEADING_NOISE.sub("", segment.strip())
    year = _YEAR_RE.search(segment)
    if year is None:
        return False
    name_part = segment[: year.start()].strip(" ,;")
    if not name_part or any(ch.isdigit() for ch in name_part):
        return False
    tokens = _name_tokens(name_part)
    if any(token in known for token in tokens):
        return True
    first_word = re.split(r"\s+", name_part)[0].casefold().strip(".,")
    if first_word in _STOP_FIRST_WORDS:
        return False
    return bool(_STRICT_NAME.match(name_part))


def _surname_alternation(known: dict[str, set[int]], doc_text: str) -> re.Pattern[str] | None:
    """Regex for narrative citations anchored on bibliography surnames."""
    surfaces: set[str] = set()
    for word in set(re.findall(r"[A-ZÀ-Ý][\w'’\-]+", doc_text)):
        if _norm_name(word) in known:
            surfaces.add(word)
    if not surfaces:
        return None
    alternation = "|".join(re.escape(w) for w in sorted(surfaces, key=len, reverse=True))
    return re.compile(
        rf"(?<![\w'’\-])(?P<name>(?:{alternation}))"
        rf"(?:\s+{_ET_AL}|\s*{_CONNECTOR}\s+{_CAP})?"
        rf"\s*\(\s*(?P<year>{YEAR})(?:\s*,\s*[^()]{{1,30}})?\s*\)"
    )


_UNKNOWN_NARRATIVE = re.compile(
    rf"(?<![\w'’\-])(?P<name>{_CAP})\s+{_ET_AL}\s*\(\s*(?P<year>{YEAR})\s*\)"
)


def _build(doc: PdfText, start: int, end: int, reference_index: int | None = None) -> dict[str, Any]:
    marker = doc.text[start:end]
    before, after = doc.sentence_context(start, end)
    return {
        "citation_text": marker,
        "citation_marker": marker,
        "context_before": before or None,
        "context_after": after or None,
        "text_start_offset": start,
        "text_end_offset": end,
        "occurrence_index": 0,
        "reference_index": reference_index,
        "locations": doc.locations(start, end),
    }


# ----------------------------------------------------------------- extraction
def extract_pdf_citations(
    pdf: bytes | PdfText,
    author_years: Iterable[tuple[str, int]],
) -> list[dict[str, Any]]:
    doc = pdf if isinstance(pdf, PdfText) else PdfText.from_bytes(pdf)
    text = doc.text
    known = _known_surnames(author_years)
    bib = doc.bibliography_range

    def in_bibliography(position: int) -> bool:
        return bib is not None and bib[0] <= position < bib[1]

    spans: list[tuple[int, int]] = []

    for match in _PAREN_RE.finditer(text):
        if in_bibliography(match.start()):
            continue
        segments = match.group(1).split(";")
        if any(_segment_accepted(segment, known) for segment in segments):
            spans.append((match.start(), match.end()))

    narrative = _surname_alternation(known, text)
    if narrative is not None:
        for match in narrative.finditer(text):
            if not in_bibliography(match.start()):
                spans.append((match.start(), match.end()))
    for match in _UNKNOWN_NARRATIVE.finditer(text):
        name = match.group("name")
        if in_bibliography(match.start()) or name.casefold() in _STOP_FIRST_WORDS:
            continue
        spans.append((match.start(), match.end()))

    numeric = [m for m in _NUMERIC_RE.finditer(text) if not in_bibliography(m.start())]
    if len(numeric) >= 3 and len(numeric) > len(spans):  # IEEE-style document
        spans = [(m.start(), m.end()) for m in numeric]

    spans.sort()
    deduped: list[tuple[int, int]] = []
    for start, end in spans:
        if deduped and start < deduped[-1][1]:
            continue  # overlapping match; keep the earlier (leftmost) one
        deduped.append((start, end))

    citations = [_build(doc, start, end) for start, end in deduped]
    for index, citation in enumerate(citations):
        citation["occurrence_index"] = index
    return citations


# --------------------------------------------------------------------- merging
def _boxes_overlap(left: dict[str, Any], right: dict[str, Any], threshold: float = 0.4) -> bool:
    if left.get("page_number") != right.get("page_number"):
        return False
    lx2, ly2 = left["x"] + left["width"], left["y"] + left["height"]
    rx2, ry2 = right["x"] + right["width"], right["y"] + right["height"]
    inter = max(0.0, min(lx2, rx2) - max(left["x"], right["x"])) * max(0.0, min(ly2, ry2) - max(left["y"], right["y"]))
    smaller = min(left["width"] * left["height"], right["width"] * right["height"])
    return smaller > 0 and inter / smaller >= threshold


def citations_overlap(left: dict[str, Any], right: dict[str, Any]) -> bool:
    """Geometric overlap only. Text offsets of different sources are NOT comparable
    (GROBID offsets index the TEI text, ours index the PDF text)."""
    return any(
        _boxes_overlap(a, b)
        for a in left.get("locations", [])
        for b in right.get("locations", [])
    )


def _sort_key(citation: dict[str, Any]) -> tuple[int, float, float]:
    locations = citation.get("locations") or []
    if not locations:
        return (10**6, 0.0, 0.0)
    first = locations[0]
    return (int(first["page_number"]), round(float(first["y"]) / 4.0), float(first["x"]))


def merge_citations(
    grobid_citations: list[dict[str, Any]],
    fallback_citations: list[dict[str, Any]],
    doc: PdfText | None = None,
) -> list[dict[str, Any]]:
    """Combine GROBID and layout citations.

    A layout citation (precise span, full-sentence quote) replaces every GROBID
    citation it overlaps, inheriting its ``reference_index``. GROBID citations
    that nothing overlaps are kept; their quote is rebuilt from the PDF text when
    their boxes can be located in it.
    """
    merged: list[dict[str, Any]] = [dict(c) for c in fallback_citations]
    for grobid in grobid_citations:
        overlapping = [m for m in merged if citations_overlap(grobid, m)]
        if overlapping:
            for mine in overlapping:
                if mine.get("reference_index") is None and grobid.get("reference_index") is not None:
                    mine["reference_index"] = grobid["reference_index"]
            continue
        kept = dict(grobid)
        if doc is not None:
            span = doc.span_from_locations(kept.get("locations", []))
            if span is not None:
                before, after = doc.sentence_context(*span)
                kept["context_before"], kept["context_after"] = before or None, after or None
        merged.append(kept)
    merged.sort(key=_sort_key)
    for index, citation in enumerate(merged):
        citation["occurrence_index"] = index
    return merged


from inference.bibliography import segment_bibliography  # noqa: E402,F401  (backward-compatible re-export)
