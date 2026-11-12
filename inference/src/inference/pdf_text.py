"""Layout-aware text model of a PDF.

One ``PdfText`` is built per document and shared by citation extraction,
bibliography segmentation and quote (sentence) extraction. Its two guarantees:

* ``text`` is built *once* and never rewritten afterwards, so every offset in it
  maps exactly to a glyph (and therefore a bounding box). The previous
  implementation normalised the text after computing word offsets, which made
  every later bounding box drift.
* Text follows the PDF content-stream order (cell by cell for tables) instead
  of being sorted by ``(y, x)``, which interleaves table columns.

Running headers/footers (text repeated in the page margins) are dropped before
the text is built, so they can never be glued onto a bibliography entry.
"""

from __future__ import annotations

import math
import re
import unicodedata
from collections import Counter
from dataclasses import dataclass, field
from typing import Any

try:  # PyMuPDF >= 1.24 exposes ``pymupdf``; older installs only ``fitz``.
    import pymupdf as fitz
except ImportError:  # pragma: no cover
    import fitz

# Cyrillic look-alikes that sneak into Latin words (e.g. "Shaо" in a real
# reference list). Only replaced when a neighbouring character is Latin.
_HOMOGLYPHS = {
    "а": "a", "е": "e", "о": "o", "р": "p", "с": "c", "у": "y", "х": "x",
    "і": "i", "ј": "j", "ѕ": "s", "А": "A", "В": "B", "Е": "E", "К": "K",
    "М": "M", "Н": "H", "О": "O", "Р": "P", "С": "C", "Т": "T", "Х": "X",
}

_TERMINATORS = ".?!"
_CLOSERS = ")\"'”’]"
_ABBREVIATIONS = {
    "al", "dkk", "dll", "dsb", "dst", "dsj", "hal", "hlm", "no", "nos", "vol",
    "vs", "cf", "fig", "gbr", "tab", "pp", "ed", "eds", "dr", "drs", "ir",
    "prof", "mr", "mrs", "ms", "jl", "st", "bpk", "eg", "ie", "sp", "mm",
}
_LIST_MARK = re.compile(r"^(?:\d{1,3}[.)]|\(\d{1,3}\)|[A-Za-z][.)]|[•●▪◦∙‣])(?:\s|$)")
_NUM_HEADING = re.compile(r"^(?:\d+(?:\.\d+)+|\d+\.)\s+[A-ZÀ-Ý]")
_LIST_MARK_PREFIX = re.compile(r"^(?:\d{1,3}[.)]|\(\d{1,3}\)|[a-z][.)])\s+")

_BIB_HEADING = re.compile(
    r"^(?:\d+[.)]?\s*)?(?:daftar\s+pustaka|daftar\s+referensi|referensi|references?|bibliography|pustaka)\s*:?$",
    re.IGNORECASE,
)
_BIB_STOP = re.compile(
    r"^(?:lampiran|appendix|appendices|kutipan\s+artikel|how\s+to\s+cite|cite\s+this|biodata|"
    r"biografi|about\s+the\s+authors?|riwayat\s+hidup)\b",
    re.IGNORECASE,
)


@dataclass
class Line:
    """One visual text line (dict-lines on the same baseline of a block are merged)."""

    page: int
    block: int
    base: float
    size: float
    page_width: float
    page_height: float
    # (char, x0, y0, x1, y1, is_small) ; synthetic spaces carry bbox None
    chars: list[tuple[str, float, float, float, float, bool] | tuple[str, None, None, None, None, bool]]
    cid: int = -1
    x0: float = 0.0
    y0: float = 0.0
    x1: float = 0.0
    y1: float = 0.0
    text: str = ""
    start: int = 0
    end: int = 0

    def finalize(self) -> None:
        chars = _split_cells(_collapse_spaces(_normalise_chars(self.chars)), self.size, self.page_width)
        while chars and chars[0][0].isspace():
            chars.pop(0)
        while chars and chars[-1][0].isspace():
            chars.pop()
        self.chars = chars
        self.text = "".join(c[0] for c in chars)
        boxed = [c for c in chars if c[1] is not None]
        main = [c for c in boxed if not c[5]] or boxed
        if main:
            self.x0 = min(c[1] for c in boxed)
            self.x1 = max(c[3] for c in boxed)
            self.y0 = min(c[2] for c in main)
            self.y1 = max(c[4] for c in main)


@dataclass
class Rect:
    page: int
    x0: float
    y0: float
    x1: float
    y1: float


class PdfText:
    """Immutable text + glyph geometry of one PDF."""

    def __init__(self) -> None:
        self.text: str = ""
        self.lines: list[Line] = []  # kept lines, in reading order
        self.page_sizes: dict[int, tuple[float, float]] = {}
        self.removed_lines: list[Line] = []
        # per character: index into ``glyphs`` or -1 (synthetic separator)
        self._owner: list[int] = []
        self._glyphs: list[tuple[int, float, float, float, float, int]] = []
        self._cluster_extent: dict[int, tuple[float, float]] = {}
        self._by_page: dict[int, list[int]] = {}
        self.bibliography_range: tuple[int, int] | None = None
        self.bibliography_lines: list[Line] = []

    # ------------------------------------------------------------------ build
    @classmethod
    def from_bytes(cls, pdf_bytes: bytes) -> "PdfText":
        document = fitz.open(stream=pdf_bytes, filetype="pdf")
        try:
            raw_lines: list[Line] = []
            doc = cls()
            cid = 0
            for page_number, page in enumerate(document, start=1):
                doc.page_sizes[page_number] = (float(page.rect.width), float(page.rect.height))
                for line in _page_lines(page, page_number):
                    line.cid = cid
                    cid += 1
                    raw_lines.append(line)
        finally:
            document.close()

        kept, removed = _drop_running_lines(raw_lines, len(doc.page_sizes))
        doc.removed_lines = removed
        doc.lines = kept
        doc._build_text()
        doc._locate_bibliography()
        return doc

    def _build_text(self) -> None:
        chars: list[str] = []
        owner: list[int] = []
        previous: Line | None = None
        for line in self.lines:
            if previous is not None:
                separator, drop_hyphen = _separator(previous, line)
                if drop_hyphen and chars and chars[-1] == "-":
                    chars.pop()
                    owner.pop()
                    indexed = self._by_page.get(previous.page)
                    if indexed and indexed[-1] == len(chars):
                        indexed.pop()
                for ch in separator:
                    chars.append(ch)
                    owner.append(-1)
            line.start = len(chars)
            extents = self._cluster_extent.setdefault(line.cid, (line.y0, line.y1))
            for ch, x0, y0, x1, y1, _small in line.chars:
                chars.append(ch)
                if x0 is None:
                    owner.append(-1)
                else:
                    self._glyphs.append((line.page, x0, y0, x1, y1, line.cid))
                    owner.append(len(self._glyphs) - 1)
                    self._by_page.setdefault(line.page, []).append(len(chars) - 1)
            line.end = len(chars)
            self._cluster_extent[line.cid] = extents
            previous = line
        self.text = "".join(chars)
        self._owner = owner

    def _locate_bibliography(self) -> None:
        heading_index = None
        for index, line in enumerate(self.lines):
            if _BIB_HEADING.match(line.text.strip()) and index + 2 < len(self.lines):
                heading_index = index
        if heading_index is None:
            return
        region: list[Line] = []
        stop_start = len(self.text)
        for line in self.lines[heading_index + 1 :]:
            if _BIB_STOP.match(line.text.strip()):
                stop_start = line.start
                break
            region.append(line)
        if not region:
            return
        self.bibliography_lines = region
        self.bibliography_range = (self.lines[heading_index].start, stop_start)

    # ------------------------------------------------------------- geometry
    def rects(self, start: int, end: int) -> list[Rect]:
        """One rectangle per visual line covered by ``text[start:end]``."""
        merged: dict[int, list[float]] = {}
        order: list[int] = []
        pages: dict[int, int] = {}
        for index in range(max(0, start), min(end, len(self._owner))):
            glyph_index = self._owner[index]
            if glyph_index < 0 or self.text[index].isspace():
                continue
            page, x0, y0, x1, y1, cid = self._glyphs[glyph_index]
            if cid not in merged:
                merged[cid] = [x0, x1]
                order.append(cid)
                pages[cid] = page
            else:
                merged[cid][0] = min(merged[cid][0], x0)
                merged[cid][1] = max(merged[cid][1], x1)
        result = []
        for cid in order:
            y0, y1 = self._cluster_extent[cid]
            result.append(Rect(pages[cid], merged[cid][0], y0, merged[cid][1], y1))
        return result

    def locations(self, start: int, end: int) -> list[dict[str, Any]]:
        return [self.rect_to_location(rect, i) for i, rect in enumerate(self.rects(start, end))]

    def rect_to_location(self, rect: Rect, location_index: int) -> dict[str, Any]:
        width, height = self.page_sizes.get(rect.page, (0.0, 0.0))
        return {
            "page_number": rect.page,
            "x": rect.x0,
            "y": rect.y0,
            "width": rect.x1 - rect.x0,
            "height": rect.y1 - rect.y0,
            "page_width": width,
            "page_height": height,
            "coordinate_system": "pdf_points_top_left",
            "location_index": location_index,
        }

    def span_from_locations(self, locations: list[dict[str, Any]], pad: float = 1.0) -> tuple[int, int] | None:
        """Map externally supplied boxes (e.g. GROBID coords) back to a text span."""
        hits: list[int] = []
        for location in locations or []:
            try:
                page = int(location["page_number"])
                x0, y0 = float(location["x"]) - pad, float(location["y"]) - pad
                x1 = x0 + float(location["width"]) + 2 * pad
                y1 = y0 + float(location["height"]) + 2 * pad
            except (KeyError, TypeError, ValueError):
                continue
            for index in self._by_page.get(page, ()):
                _, gx0, gy0, gx1, gy1, _cid = self._glyphs[self._owner[index]]
                cx, cy = (gx0 + gx1) / 2, (gy0 + gy1) / 2
                if x0 <= cx <= x1 and y0 <= cy <= y1:
                    hits.append(index)
        if not hits:
            return None
        return min(hits), max(hits) + 1

    # ------------------------------------------------------------- sentences
    def sentence_context(self, start: int, end: int, max_chars: int = 700) -> tuple[str, str]:
        """Return ``(before, after)`` so that ``before + text[start:end] + after``
        is the full sentence containing the span (first word up to the final
        full stop)."""
        text = self.text
        sentence_start = _sentence_start(text, start)
        sentence_end = _sentence_end(text, end)

        outside = (text[sentence_start:start] + text[end:sentence_end]).strip(" .,;:()[]\"'”’")
        if len(outside) < 8 and sentence_start > 0 and text[sentence_start - 1] != "\n":
            # A citation that sits alone after a full stop ("... acak. (Riadhi et al., 2020).")
            # belongs to the previous sentence.
            boundary = sentence_start - 1
            while boundary >= 0 and (text[boundary] in _CLOSERS or text[boundary] == " "):
                boundary -= 1
            sentence_start = _sentence_start(text, boundary)

        prefix = _LIST_MARK_PREFIX.match(text[sentence_start:start])
        if prefix:
            sentence_start += prefix.end()

        before = text[sentence_start:start].lstrip()
        after = text[end:sentence_end].rstrip()
        if len(before) + (end - start) + len(after) > max_chars:
            room = max(0, (max_chars - (end - start)) // 2)
            if len(before) > room:
                cut = before[-room:]
                before = "… " + cut[cut.find(" ") + 1 :] if " " in cut else "… " + cut
            if len(after) > room:
                cut = after[:room]
                after = (cut[: cut.rfind(" ")] if " " in cut else cut) + " …"
        return before, after


# ============================================================== line building
def _page_lines(page: Any, page_number: int) -> list[Line]:
    width, height = float(page.rect.width), float(page.rect.height)
    raw = page.get_text("rawdict")  # content-stream order, deliberately NOT sorted
    lines: list[Line] = []
    for block in raw.get("blocks", []):
        if block.get("type") != 0:
            continue
        current: Line | None = None
        for dict_line in block.get("lines", []):
            spans = [s for s in dict_line.get("spans", []) if s.get("chars")]
            if not spans:
                continue
            main = max(spans, key=lambda s: sum(1 for c in s["chars"] if not c["c"].isspace()))
            base = float(main["origin"][1])
            size = float(main["size"]) or 10.0
            chars: list[Any] = []
            for span in spans:
                small = float(span["size"]) < 0.8 * size and abs(float(span["origin"][1]) - base) > 1.0
                for char in span["chars"]:
                    x0, y0, x1, y1 = (float(v) for v in char["bbox"])
                    chars.append((char["c"], x0, y0, x1, y1, small))
            if current is not None and abs(base - current.base) <= 2.0:
                chars_before = current.chars
                if chars_before and chars:
                    last, first = chars_before[-1], chars[0]
                    if last[3] is not None and first[1] is not None and first[1] - last[3] > 0.2 * size:
                        chars_before.append((" ", None, None, None, None, False))
                chars_before.extend(chars)
            else:
                if current is not None:
                    lines.append(current)
                current = Line(page_number, int(block["number"]), base, size, width, height, list(chars))
        if current is not None:
            lines.append(current)

    result = []
    for line in lines:
        line.finalize()
        if line.text.strip():
            result.append(line)
    return result


def _normalise_chars(chars: list[Any]) -> list[Any]:
    """NFKC (ligatures, superscript digits), drop soft hyphens, insert word gaps."""
    out: list[Any] = []
    previous = None
    for ch, x0, y0, x1, y1, small in chars:
        if ch == "\u00ad" or (ord(ch) < 32 and ch not in "\t"):
            continue
        pieces = unicodedata.normalize("NFKC", ch) if ord(ch) > 127 else ch
        if pieces in ("", "\ufeff"):
            continue
        if pieces.isspace():
            pieces = " "
        # gap-based space for PDFs that position words instead of emitting spaces
        if (
            previous is not None
            and x0 is not None
            and previous[3] is not None
            and not previous[0].isspace()
            and pieces != " "
            and not small
            and not previous[5]
            and (x0 - previous[3]) > 0.25 * max(1.0, previous[4] - previous[2]) * 0.8
        ):
            out.append((" ", None, None, None, None, False))
        for piece in pieces:
            item = (piece, x0, y0, x1, y1, small)
            out.append(item)
        previous = out[-1] if out else None

    # homoglyph repair, only next to Latin letters
    for index, item in enumerate(out):
        replacement = _HOMOGLYPHS.get(item[0])
        if replacement is None:
            continue
        left = out[index - 1][0] if index > 0 else ""
        right = out[index + 1][0] if index + 1 < len(out) else ""
        if (left.isascii() and left.isalpha()) or (right.isascii() and right.isalpha()):
            out[index] = (replacement, *item[1:])
    return out


def _collapse_spaces(chars: list[Any]) -> list[Any]:
    out: list[Any] = []
    for item in chars:
        if item[0] == " " and out and out[-1][0] == " ":
            continue
        out.append(item)
    return out


def _split_cells(chars: list[Any], size: float, page_width: float) -> list[Any]:
    """Turn a wide horizontal gap into a hard break (table cells on one baseline).

    Justified body text has gaps up to ~2.2x the font size but always reaches the
    right margin, so a gap only counts when it is huge, or when it is large on a
    line that stops well short of the right margin.
    """
    boxed = [c for c in chars if c[1] is not None]
    if not boxed:
        return chars
    reaches_margin = max(c[3] for c in boxed) >= 0.8 * page_width
    out: list[Any] = []
    last_x1: float | None = None
    for item in chars:
        ch, x0 = item[0], item[1]
        if ch.isspace():
            out.append(item)
            continue
        if last_x1 is not None and x0 is not None and not item[5]:
            gap = (x0 - last_x1) / size
            if gap > 3.0 or (gap > 1.4 and not reaches_margin):
                while out and out[-1][0] == " ":
                    out.pop()
                out.append(("\n", None, None, None, None, False))
        out.append(item)
        if item[3] is not None:
            last_x1 = item[3]
    return out


def _drop_running_lines(lines: list[Line], page_count: int) -> tuple[list[Line], list[Line]]:
    """Remove text repeated in the top/bottom margins of several pages."""

    def key(line: Line) -> str:
        return re.sub(r"\d+", "#", " ".join(line.text.casefold().split())).strip()

    in_margin = [
        line for line in lines if line.y0 < 0.14 * line.page_height or line.y1 > 0.90 * line.page_height
    ]
    pages_per_key: dict[str, set[int]] = {}
    for line in in_margin:
        pages_per_key.setdefault(key(line), set()).add(line.page)
    threshold = max(2, math.ceil(0.25 * page_count))
    repeated = {k for k, pages in pages_per_key.items() if k and len(pages) >= threshold}

    kept: list[Line] = []
    removed: list[Line] = []
    margin_ids = {id(line) for line in in_margin}
    for line in lines:
        if id(line) in margin_ids and key(line) in repeated:
            removed.append(line)
        else:
            kept.append(line)
    return kept, removed


# ============================================================ line separators
def _ends_sentence(text: str) -> bool:
    return bool(re.search(r"[.?!][\)\"'”’\]]*$", text.rstrip()))


def _heading_like(text: str) -> bool:
    value = text.strip()
    if not value or len(value) > 110:
        return False
    if _NUM_HEADING.match(value) and len(value.split()) <= 12 and not value.endswith((",", ";", ":")):
        return True  # "2. Analisis Buffering", "4.1 MANFAAT BIG DATA"
    if value.endswith((".", ",", ";", ":")):
        return False
    letters = [c for c in value if c.isalpha()]
    return len(letters) >= 4 and sum(c.isupper() for c in letters) / len(letters) >= 0.8


def _hard_break(previous: Line, line: Line) -> bool:
    if line.page == previous.page:
        if line.base < previous.base - 2.0:  # stream jumped upwards: next cell / column
            return True
        if abs(line.base - previous.base) <= 2.0:  # same row, different block: table cells
            return True
        if line.y0 - previous.y1 > 0.8 * max(previous.size, line.size):  # paragraph spacing
            return True
    if _heading_like(previous.text) or _heading_like(line.text):
        return True
    if _LIST_MARK.match(line.text):
        return True
    if (
        line.page == previous.page
        and (_ends_sentence(previous.text) or previous.text.rstrip().endswith(")"))
        and line.x0 - previous.x0 > 6.0
        and (line.text[0].isupper() or line.text[0].isdigit())
    ):
        return True  # indented first line of a new paragraph
    return False


def _separator(previous: Line, line: Line) -> tuple[str, bool]:
    if _hard_break(previous, line):
        return "\n", False
    last_token = previous.text.split()[-1] if previous.text.split() else ""
    first = line.text[0] if line.text else ""
    if last_token.endswith("-") and len(last_token) > 2 and last_token[-2].isalpha():
        if first.islower():
            return "", True  # "non-" + "relasional" -> "nonrelasional"
        return "", False  # "Tata-" + "Cara-" (wrapped URL)
    if last_token.lower().startswith(("http", "www.")) or "://" in last_token:
        if not _ends_sentence(last_token) or first.islower():
            return "", False  # URL wrapped across lines
    return " ", False


# ========================================================= sentence boundaries
def _is_sentence_end(text: str, index: int) -> bool:
    char = text[index]
    if char not in _TERMINATORS:
        return False
    j = index + 1
    while j < len(text) and text[j] in _CLOSERS:
        j += 1
    if j < len(text) and not text[j].isspace():
        if not (text[j] == "(" and j + 1 < len(text) and text[j + 1].isupper()):
            return False  # "0.52", "doi.org", "al.," ...
    if char == ".":
        match = re.search(r"([A-Za-z]+)$", text[max(0, index - 10) : index])
        if match:
            token = match.group(1)
            if token.casefold() in _ABBREVIATIONS:
                return False
            if len(token) == 1 and token.isupper():
                return False  # initial: "S. Rizal"
            if len(token) == 1 and index >= 2 and text[index - 2] == ".":
                return False  # "i.e.", "e.g."
        k = j
        while k < len(text) and text[k] == " ":
            k += 1
        if k < len(text) and text[k].islower():
            return False
    return True


def _sentence_start(text: str, position: int) -> int:
    index = position - 1
    while index >= 0:
        if text[index] == "\n":
            return index + 1
        if text[index] in _TERMINATORS and _is_sentence_end(text, index):
            j = index + 1
            while j < len(text) and (text[j] in _CLOSERS or text[j] == " "):
                j += 1
            return min(j, position)
        index -= 1
    return 0


def _sentence_end(text: str, position: int) -> int:
    for index in range(position, len(text)):
        if text[index] == "\n":
            return index
        if text[index] in _TERMINATORS and _is_sentence_end(text, index):
            j = index + 1
            while j < len(text) and text[j] in _CLOSERS:
                j += 1
            return j
    return len(text)
