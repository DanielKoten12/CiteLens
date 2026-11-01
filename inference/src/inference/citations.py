from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any

import fitz


YEAR_PATTERN = r"(?:19|20)\d{2}[a-z]?"
PARENTHETICAL_PATTERN = re.compile(r"\(([^()\n]{1,180}?" + YEAR_PATTERN + r"[^()\n]{0,80})\)", re.IGNORECASE)
NARRATIVE_PATTERN = re.compile(
    r"\b([A-Z][A-Za-zÀ-ÖØ-öø-ÿ'’-]{1,40}(?:\s+(?:et\s+al\.?|dkk\.?|dan|and)(?:\s+[A-Z][A-Za-zÀ-ÖØ-öø-ÿ'’-]{1,40})?)?)"
    r"\s*\(\s*(" + YEAR_PATTERN + r")(?:\s*,\s*(?:p{1,2}\.?\s*)?\d+(?:\s*[-–]\s*\d+)?)?\s*\)",
    re.IGNORECASE,
)
YEAR_IN_TEXT = re.compile(YEAR_PATTERN, re.IGNORECASE)
SURNAME_TOKEN = re.compile(r"[A-Za-zÀ-ÖØ-öø-ÿ'’-]{2,}", re.UNICODE)


@dataclass(frozen=True)
class _Word:
    page_number: int
    x0: float
    y0: float
    x1: float
    y1: float
    page_width: float
    page_height: float
    text: str
    start: int
    end: int


def _normalise_text(text: str) -> str:
    text = text.replace("\u00ad", "").replace("\u00a0", " ")
    text = re.sub(r"-\s+(?=[a-z])", "", text, flags=re.IGNORECASE)
    return re.sub(r"\s+", " ", text).strip()


def _words(pdf_bytes: bytes) -> tuple[str, list[_Word]]:
    document = fitz.open(stream=pdf_bytes, filetype="pdf")
    words: list[_Word] = []
    chunks: list[str] = []
    offset = 0

    try:
        for page_number, page in enumerate(document, start=1):
            page_words = sorted(page.get_text("words"), key=lambda item: (item[1], item[0]))
            for raw in page_words:
                text = str(raw[4])
                if not text:
                    continue
                if chunks:
                    chunks.append(" ")
                    offset += 1
                start = offset
                chunks.append(text)
                offset += len(text)
                words.append(
                    _Word(
                        page_number,
                        float(raw[0]),
                        float(raw[1]),
                        float(raw[2]),
                        float(raw[3]),
                        float(page.rect.width),
                        float(page.rect.height),
                        text,
                        start,
                        offset,
                    )
                )
    finally:
        document.close()

    return _normalise_text("".join(chunks)), words


def _surname_matches(text: str, author_years: set[tuple[str, int]]) -> tuple[str, int] | None:
    year_match = YEAR_IN_TEXT.search(text)
    if year_match is None:
        return None
    year = int(year_match.group(0)[:4])
    surnames = {surname.casefold() for surname, candidate_year in author_years if candidate_year == year}
    for token in SURNAME_TOKEN.findall(text[: year_match.start()]):
        if token.casefold() in surnames:
            return token, year
    return None


def _locations(words: list[_Word], start: int, end: int) -> list[dict[str, Any]]:
    selected = [word for word in words if word.end > start and word.start < end]
    grouped: dict[int, list[_Word]] = {}
    for word in selected:
        grouped.setdefault(word.page_number, []).append(word)

    locations: list[dict[str, Any]] = []
    for page_number, page_words in grouped.items():
        locations.append(
            {
                "page_number": page_number,
                "x": min(word.x0 for word in page_words),
                "y": min(word.y0 for word in page_words),
                "width": max(word.x1 for word in page_words) - min(word.x0 for word in page_words),
                "height": max(word.y1 for word in page_words) - min(word.y0 for word in page_words),
                "page_width": page_words[0].page_width,
                "page_height": page_words[0].page_height,
                "coordinate_system": "pdf_points_top_left",
                "location_index": len(locations),
            }
        )
    return locations


def _citation(start: int, end: int, text: str, words: list[_Word]) -> dict[str, Any]:
    marker = text.strip()
    return {
        "citation_text": marker,
        "citation_marker": marker,
        "context_before": None,
        "context_after": None,
        "text_start_offset": start,
        "text_end_offset": end,
        "occurrence_index": 0,
        "reference_index": None,
        "locations": _locations(words, start, end),
    }


def extract_pdf_citations(
    pdf_bytes: bytes,
    author_years: set[tuple[str, int]],
) -> list[dict[str, Any]]:
    text, words = _words(pdf_bytes)
    found: list[tuple[int, int, dict[str, Any]]] = []

    for match in PARENTHETICAL_PATTERN.finditer(text):
        if _surname_matches(match.group(1), author_years) is not None:
            found.append((match.start(), match.end(), _citation(match.start(), match.end(), match.group(0), words)))

    for match in NARRATIVE_PATTERN.finditer(text):
        if _surname_matches(match.group(0), author_years) is not None:
            found.append((match.start(), match.end(), _citation(match.start(), match.end(), match.group(0), words)))

    deduped: list[tuple[int, int, dict[str, Any]]] = []
    for start, end, citation in sorted(found):
        if any(min(end, other_end) - max(start, other_start) > 0 for other_start, other_end, _ in deduped):
            continue
        deduped.append((start, end, citation))

    for index, (_, _, citation) in enumerate(deduped):
        citation["occurrence_index"] = index
    return [citation for _, _, citation in deduped]


def _location_overlap(left: dict[str, Any], right: dict[str, Any]) -> bool:
    if left.get("page_number") != right.get("page_number"):
        return False
    left_x2 = left["x"] + left["width"]
    left_y2 = left["y"] + left["height"]
    right_x2 = right["x"] + right["width"]
    right_y2 = right["y"] + right["height"]
    intersection = max(0.0, min(left_x2, right_x2) - max(left["x"], right["x"])) * max(
        0.0, min(left_y2, right_y2) - max(left["y"], right["y"])
    )
    smaller = min(left["width"] * left["height"], right["width"] * right["height"])
    return smaller > 0 and intersection / smaller >= 0.5


def citations_overlap(left: dict[str, Any], right: dict[str, Any]) -> bool:
    left_start = left.get("text_start_offset")
    left_end = left.get("text_end_offset")
    right_start = right.get("text_start_offset")
    right_end = right.get("text_end_offset")
    if (
        left_start is not None
        and left_end is not None
        and right_start is not None
        and right_end is not None
        and min(left_end, right_end) > max(left_start, right_start)
    ):
        return True

    return any(
        _location_overlap(left_location, right_location)
        for left_location in left.get("locations", [])
        for right_location in right.get("locations", [])
    )


def merge_citations(
    grobid_citations: list[dict[str, Any]],
    fallback_citations: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    merged = list(grobid_citations)
    for fallback in fallback_citations:
        if not any(citations_overlap(fallback, existing) for existing in merged):
            merged.append(fallback)
    merged.sort(key=lambda citation: citation.get("text_start_offset") is None)
    for occurrence_index, citation in enumerate(merged):
        citation["occurrence_index"] = occurrence_index
    return merged


def segment_bibliography(pdf_bytes: bytes) -> list[str]:
    document = fitz.open(stream=pdf_bytes, filetype="pdf")
    lines: list[str] = []
    in_bibliography = False
    try:
        for page in document:
            for line in page.get_text("text").splitlines():
                value = _normalise_text(line)
                if not value:
                    continue
                if re.search(r"\b(daftar pustaka|references|bibliography)\b", value, re.IGNORECASE):
                    in_bibliography = True
                    continue
                if in_bibliography:
                    lines.append(value)
    finally:
        document.close()

    starts = re.compile(
        r"^(?:\d+[.)]\s*)?[A-Z][A-Za-zÀ-ÖØ-öø-ÿ'’-]+(?:,\s*[A-Z](?:\.[A-Z.]*)?)?\s*\(\s*" + YEAR_PATTERN + r"\s*\)",
        re.IGNORECASE,
    )
    entries: list[str] = []
    for line in lines:
        if starts.search(line) or re.match(r"^Sustainability \(Switzerland\)(?:,|$)", line, re.IGNORECASE):
            entries.append(line)
        elif entries:
            entries[-1] = f"{entries[-1]} {line}"
    return entries
