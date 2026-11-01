"""Bibliography segmentation from page layout, and alignment with GROBID.

Why layout instead of text heuristics: reference lists use a hanging indent, so
"this line starts at the left margin" is the one signal that tells an entry
start from a continuation line. It separates ``Fidiani`` from the stray
``Sustainability (Switzerland)`` line without naming either, and it cannot be
confused by a journal header that was glued onto the previous entry, because
repeated margin text is removed by :class:`PdfText` before segmentation.

GROBID stays the source of structured fields (authors, title, DOI...). The
layout segments are the source of truth for *where an entry starts and ends*
and for its bounding boxes.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field
from typing import Any

from inference.pdf_text import Line, PdfText

_DOI_RE = re.compile(r"\b(10\.\d{4,9}/[-._;()/:A-Za-z0-9]+)")
_YEAR_PAREN = re.compile(r"\(\s*((?:19|20)\d{2})[a-z]?\s*\)")
_NUMBERED_START = re.compile(r"^\[\d{1,3}\]|^\d{1,3}\.\s+\S")
# A line at the left margin that begins with URL/digit debris is a wrapped fragment, not an entry.
_FRAGMENT_START = re.compile(r"^(?:[0-9%/._:\-]|https?:|www\.)")
_AUTHOR_YEAR_START = re.compile(
    r"^[A-ZÀ-Ý][^()]{0,150}?\(\s*(?:19|20)\d{2}[a-z]?\s*\)|^[A-ZÀ-Ý][\w'’\-]+,\s+(?:[A-Z]\.\s*)+"
)


@dataclass
class Segment:
    text: str
    start: int
    end: int
    locations: list[dict[str, Any]] = field(default_factory=list)
    line_count: int = 1


# ------------------------------------------------------------------ segmenting
def _join_lines(lines: list[Line]) -> str:
    text = ""
    for line in lines:
        piece = " ".join(line.text.replace("\n", " ").split())
        if not text:
            text = piece
            continue
        last = text.split()[-1] if text.split() else ""
        if last.endswith("-") and len(last) > 2 and last[-2].isalpha() and piece[:1].islower():
            text = text[:-1] + piece
        elif last.lower().startswith(("http", "www.")) or "://" in last or ("/" in last and last.endswith(("-", "/"))):
            text += piece  # wrapped URL
        else:
            text += " " + piece
    return text.strip()


def segment_references(doc: PdfText) -> list[Segment]:
    lines = doc.bibliography_lines
    if not lines:
        return []

    margin: dict[int, float] = {}
    for line in lines:
        margin[line.page] = min(margin.get(line.page, line.x0), line.x0)
    at_margin = [ln for ln in lines if ln.x0 <= margin[ln.page] + 3.0]
    flush_left = len(at_margin) / len(lines) > 0.85  # no hanging indent in this document

    groups: list[list[Line]] = []
    for index, line in enumerate(lines):
        text = line.text.strip()
        if _NUMBERED_START.match(text):
            starts = True
        elif _FRAGMENT_START.match(text):
            starts = False
        elif not flush_left:
            starts = line.x0 <= margin[line.page] + 3.0
        else:
            starts = bool(_AUTHOR_YEAR_START.match(text)) and (
                index == 0 or lines[index - 1].text.rstrip().endswith((".", ")", "/")) or _is_gap(lines[index - 1], line)
            )
        if starts or not groups:
            groups.append([line])
        else:
            groups[-1].append(line)

    segments: list[Segment] = []
    for group in groups:
        text = _join_lines(group)
        if len(text) < 12:
            continue
        locations: list[dict[str, Any]] = []
        for line in group:
            for rect in doc.rects(line.start, line.end):
                locations.append(doc.rect_to_location(rect, len(locations)))
        segments.append(Segment(text, group[0].start, group[-1].end, locations, len(group)))
    return segments


def _is_gap(previous: Line, line: Line) -> bool:
    return line.page == previous.page and (line.y0 - previous.y1) > 0.6 * line.size


def segment_bibliography(pdf: bytes | PdfText) -> list[str]:
    """Backward compatible helper: raw text of each bibliography entry."""
    doc = pdf if isinstance(pdf, PdfText) else PdfText.from_bytes(pdf)
    return [segment.text for segment in segment_references(doc)]


# ------------------------------------------------------------- field heuristics
def _normalise_doi(value: str | None) -> str | None:
    if not value:
        return None
    value = value.strip().rstrip(".,;)")
    value = re.sub(r"^https?://(?:dx\.)?doi\.org/", "", value, flags=re.IGNORECASE)
    return value.lower() or None


def fields_from_text(text: str) -> dict[str, Any]:
    """Best-effort fields for an entry GROBID did not (or wrongly) parse."""
    doi_match = _DOI_RE.search(text)
    year_match = _YEAR_PAREN.search(text)
    authors = title = None
    year = None
    if year_match:
        year = int(year_match.group(1))
        authors = text[: year_match.start()].strip(" .,;(") or None
        rest = text[year_match.end() :].lstrip(" .)")
        title = re.split(r"(?<=[a-z0-9\)])\.\s+(?=[A-ZÀ-Ý])", rest, maxsplit=1)[0].strip(" .") or None
        if title and title.lower().startswith(("http", "doi")):
            title = None
    return {
        "title": title,
        "authors": authors,
        "publication_name": None,
        "publication_year": year,
        "doi": doi_match.group(1).rstrip(".,;)") if doi_match else None,
    }


# --------------------------------------------------------------------- aligning
def _tokens(text: str | None) -> set[str]:
    value = unicodedata.normalize("NFKD", text or "")
    value = "".join(c for c in value if not unicodedata.combining(c)).casefold()
    return set(re.findall(r"[a-z0-9]{3,}", value))


def _head(text: str | None) -> str:
    value = unicodedata.normalize("NFKD", text or "")
    return re.sub(r"[^a-z0-9]", "", value.casefold())[:30]


def _score(candidate: dict[str, Any], segment: Segment, seg_tokens: set[str], seg_dois: set[str]) -> tuple[float, float]:
    """Return (F1, precision). Precision = share of the candidate that lies in the segment."""
    cand_tokens = _tokens(candidate.get("raw_text"))
    if not cand_tokens or not seg_tokens:
        return 0.0, 0.0
    inter = len(cand_tokens & seg_tokens)
    if inter == 0:
        return 0.0, 0.0
    precision, recall = inter / len(cand_tokens), inter / len(seg_tokens)
    f1 = 2 * precision * recall / (precision + recall)
    doi = _normalise_doi(candidate.get("doi"))
    if doi and doi in seg_dois:
        f1 = max(f1, 0.9)
    # A candidate that swallowed two entries still *starts* with the first of them:
    # prefer the segment whose opening matches the candidate's opening.
    head = _head(candidate.get("raw_text"))
    if head and head == _head(segment.text):
        f1 += 0.3
    return f1, precision


def align_references(
    candidates: list[dict[str, Any]],
    segments: list[Segment],
    min_score: float = 0.5,
    min_coverage: float = 0.7,
) -> list[dict[str, Any]]:
    """Return references in document order, one per layout segment.

    * A GROBID reference matched to a segment keeps its fields and takes the
      segment's text and boxes. If it swallowed extra text (precision < 0.75, e.g.
      a journal header or the neighbouring entry), its fields are re-derived from
      the segment instead.
    * A segment no GROBID reference matched becomes a reference of its own.
    * A GROBID reference matching no segment (running header, heading, body
      prose) is dropped.
    * If alignment looks unreliable (too few segments, or most GROBID references
      unmatched) the GROBID list is returned untouched.
    """
    if len(segments) < 3 or not candidates:
        return candidates

    seg_tokens = [_tokens(s.text) for s in segments]
    seg_dois = [{_normalise_doi(m) for m in _DOI_RE.findall(s.text)} - {None} for s in segments]

    pairs: list[tuple[float, int, int, float]] = []
    for ci, candidate in enumerate(candidates):
        for si, segment in enumerate(segments):
            f1, precision = _score(candidate, segment, seg_tokens[si], seg_dois[si])  # type: ignore[arg-type]
            if f1 >= min_score:
                pairs.append((f1, ci, si, precision))
    pairs.sort(key=lambda p: (-p[0], p[1], p[2]))

    assigned_candidate: dict[int, int] = {}
    assigned_segment: dict[int, tuple[int, float]] = {}
    for _f1, ci, si, precision in pairs:
        if ci in assigned_candidate or si in assigned_segment:
            continue
        assigned_candidate[ci] = si
        assigned_segment[si] = (ci, precision)

    if len(assigned_candidate) / len(candidates) < min_coverage:
        return candidates

    references: list[dict[str, Any]] = []
    for si, segment in enumerate(segments):
        if si in assigned_segment:
            ci, precision = assigned_segment[si]
            reference = dict(candidates[ci])
            if precision < 0.75:
                reference.update(fields_from_text(segment.text))
            elif not reference.get("doi"):
                reference["doi"] = fields_from_text(segment.text)["doi"]
        else:
            reference = {
                "raw_text": segment.text,
                "text_start_offset": None,
                "text_end_offset": None,
                "xml_id": None,
                **fields_from_text(segment.text),
            }
        reference["raw_text"] = segment.text
        reference["locations"] = segment.locations
        reference["reference_index"] = len(references)
        references.append(reference)
    return references
