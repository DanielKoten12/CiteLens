import re
import xml.etree.ElementTree as ET
from typing import Any

from inference.citations import extract_pdf_citations, merge_citations

TEI_NS = {"tei": "http://www.tei-c.org/ns/1.0"}
XML_NS = "http://www.w3.org/XML/1998/namespace"


class TEIParseError(ValueError):
    """GROBID returned TEI XML the parser cannot read."""


def clean_text(element: ET.Element | None) -> str | None:
    if element is None:
        return None
    value = " ".join(" ".join(element.itertext()).split())
    return value or None


def find_first(parent: ET.Element | None, paths: list[str]) -> ET.Element | None:
    if parent is None:
        return None
    for path in paths:
        found = parent.find(path, TEI_NS)
        if found is not None:
            return found
    return None


def parse_coords(value: str | None, page_sizes: dict[int, tuple[float | None, float | None]]) -> list[dict[str, Any]]:
    boxes: list[dict[str, Any]] = []
    if not value:
        return boxes

    for item in value.split(";"):
        parts = [part.strip() for part in item.split(",")]
        if len(parts) != 5:
            continue
        try:
            page_number = int(parts[0])
            x, y, width, height = (float(part) for part in parts[1:])
        except ValueError:
            continue
        page_width, page_height = page_sizes.get(page_number, (None, None))
        boxes.append({
            "page_number": page_number,
            "x": x,
            "y": y,
            "width": width,
            "height": height,
            "page_width": page_width,
            "page_height": page_height,
            "coordinate_system": "pdf_points_top_left",
            "location_index": len(boxes),
        })
    return boxes


def extract_page_sizes(root: ET.Element) -> dict[int, tuple[float | None, float | None]]:
    sizes: dict[int, tuple[float | None, float | None]] = {}
    surfaces = root.findall(".//tei:facsimile/tei:surface", TEI_NS)
    for index, surface in enumerate(surfaces, start=1):
        width = _to_float(surface.get("lrx"))
        height = _to_float(surface.get("lry"))
        if width is not None and surface.get("ulx") is not None:
            width -= _to_float(surface.get("ulx")) or 0.0
        if height is not None and surface.get("uly") is not None:
            height -= _to_float(surface.get("uly")) or 0.0
        sizes[index] = (width, height)
    return sizes


def _to_float(value: str | None) -> float | None:
    try:
        return float(value) if value is not None else None
    except ValueError:
        return None


def extract_header(root: ET.Element) -> dict[str, Any]:
    title = clean_text(find_first(root, [
        ".//tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:title",
        ".//tei:teiHeader/tei:profileDesc/tei:abstract/tei:title",
    ]))
    authors: list[str] = []
    for author in root.findall(".//tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:author", TEI_NS):
        name = clean_text(author.find("tei:persName", TEI_NS)) or clean_text(author)
        if name and name not in authors:
            authors.append(name)

    abstract = clean_text(find_first(root, [".//tei:teiHeader/tei:profileDesc/tei:abstract"]))
    publication_year = None
    for date in root.findall(".//tei:teiHeader//tei:date", TEI_NS):
        date_text = date.get("when") or clean_text(date) or ""
        match = re.search(r"\b\d{4}\b", date_text)
        if match:
            publication_year = int(match.group(0))
            break

    doi = None
    for idno in root.findall(".//tei:teiHeader//tei:idno", TEI_NS):
        if (idno.get("type") or "").lower() == "doi":
            doi = clean_text(idno)
            if doi:
                break

    return {
        "title": title,
        "authors": authors,
        "abstract": abstract,
        "publication_year": publication_year,
        "doi": doi,
    }


def get_authors(bibl: ET.Element) -> str | None:
    authors: list[str] = []
    for author in bibl.findall(".//tei:author", TEI_NS):
        name = author.find("tei:persName", TEI_NS)
        full_name = clean_text(name) if name is not None else clean_text(author)
        if full_name and full_name not in authors:
            authors.append(full_name)
    return ", ".join(authors) if authors else None


def get_title(bibl: ET.Element) -> str | None:
    titles: list[str] = []
    analytic = bibl.find("tei:analytic", TEI_NS)
    monogr = bibl.find("tei:monogr", TEI_NS)
    title_elements = []
    if analytic is not None:
        title_elements.extend(analytic.findall("tei:title", TEI_NS))
    if not title_elements and monogr is not None:
        title_elements.extend(monogr.findall("tei:title[@level='m']", TEI_NS))
    for title in title_elements:
        value = clean_text(title)
        if value and value not in titles:
            titles.append(value)
    return " ".join(titles) or None


def get_publication_name(bibl: ET.Element) -> str | None:
    monogr = bibl.find("tei:monogr", TEI_NS)
    title = find_first(monogr, ["tei:title[@level='j']", "tei:title[@level='m']", "tei:title"])
    return clean_text(title)


def get_publication_year(bibl: ET.Element) -> int | None:
    for date in bibl.findall(".//tei:date", TEI_NS):
        date_text = date.get("when") or clean_text(date) or ""
        match = re.search(r"\b\d{4}\b", date_text)
        if match:
            return int(match.group(0))
    return None


def get_doi(bibl: ET.Element) -> str | None:
    for idno in bibl.findall(".//tei:idno", TEI_NS):
        if (idno.get("type") or "").lower() == "doi":
            value = clean_text(idno)
            if value:
                return value.removeprefix("https://doi.org/").removeprefix("http://doi.org/")
    for ptr in bibl.findall(".//tei:ptr", TEI_NS):
        target = ptr.get("target") or ""
        match = re.search(r"doi\.org/(.+)$", target, re.IGNORECASE)
        if match:
            return match.group(1).strip()
    return None


def locate_text(full_text: str, needle: str | None, start_at: int = 0) -> tuple[int | None, int | None]:
    if not needle:
        return None, None
    normalized = " ".join(needle.split())
    position = full_text.find(normalized, start_at)
    if position < 0 and start_at:
        position = full_text.find(normalized)
    if position < 0:
        return None, None
    return position, position + len(normalized)


def context_snippet(full_text: str, start: int | None, end: int | None) -> tuple[str | None, str | None]:
    if start is None or end is None:
        return None, None

    before_start = max(0, start - 120)
    if before_start > 0:
        first_boundary = full_text.find(" ", before_start, start)
        if first_boundary >= 0:
            before_start = first_boundary + 1
    before = full_text[before_start:start].rstrip()
    if before_start > 0:
        before = before.lstrip()
        if before:
            before = "... " + before

    after_end = min(len(full_text), end + 120)
    if after_end < len(full_text):
        last_boundary = full_text.rfind(" ", end, after_end)
        if last_boundary > end:
            after_end = last_boundary
    after = full_text[end:after_end].lstrip()
    if after_end < len(full_text):
        after = after.rstrip()
        if after:
            after = after + " ..."

    return before, after


def extract_citations(root: ET.Element, full_text: str, page_sizes: dict[int, tuple[float | None, float | None]], reference_indices: dict[str, int]) -> list[dict[str, Any]]:
    citations: list[dict[str, Any]] = []
    cursor = 0
    for ref in root.findall(".//tei:text/tei:body//tei:ref", TEI_NS):
        if ref.get("type") != "bibr":
            continue
        citation_text = clean_text(ref)
        start, end = locate_text(full_text, citation_text, cursor)
        if start is not None and end is not None:
            cursor = end
        context_before, context_after = context_snippet(full_text, start, end)
        target = (ref.get("target") or "").strip().split()
        target_id = target[0].lstrip("#") if target else None
        citations.append({
            "citation_text": citation_text,
            "citation_marker": citation_text,
            "context_before": context_before,
            "context_after": context_after,
            "text_start_offset": start,
            "text_end_offset": end,
            "occurrence_index": len(citations),
            "reference_index": reference_indices.get(target_id) if target_id else None,
            "locations": parse_coords(ref.get("coords"), page_sizes),
        })
    return citations


def parse_reference(bibl: ET.Element, index: int, full_text: str, cursor: int, page_sizes: dict[int, tuple[float | None, float | None]]) -> tuple[dict[str, Any], int]:
    raw_text = clean_text(bibl)
    start, end = locate_text(full_text, raw_text, cursor)
    if end is not None:
        cursor = end
    xml_id = bibl.get(f"{{{XML_NS}}}id")
    reference = {
        "reference_index": index,
        "raw_text": raw_text,
        "title": get_title(bibl),
        "authors": get_authors(bibl),
        "publication_name": get_publication_name(bibl),
        "publication_year": get_publication_year(bibl),
        "doi": get_doi(bibl),
        "text_start_offset": start,
        "text_end_offset": end,
        "locations": parse_coords(bibl.get("coords"), page_sizes),
        "xml_id": xml_id,
    }
    return reference, cursor


def is_bibliography_entry(reference: dict[str, Any]) -> bool:
    """Reject headings and prose that GROBID sometimes wraps as biblStruct."""
    raw_text = " ".join((reference.get("raw_text") or "").split()).strip()
    normalized = raw_text.casefold().strip(" .:-")

    if not raw_text or normalized in {
        "daftar pustaka",
        "references",
        "bibliography",
        "saran",
        "kesimpulan",
    }:
        return False

    if reference.get("doi"):
        return True

    has_title = bool(reference.get("title"))
    has_authors = bool(reference.get("authors"))
    has_publication = bool(reference.get("publication_name"))
    has_year = reference.get("publication_year") is not None

    return has_year and (has_title or has_authors or has_publication) or has_authors and (
        has_title or has_publication
    )


def merge_reference_parts(
    reference: dict[str, Any],
    fragment: dict[str, Any],
) -> dict[str, Any]:
    merged = dict(reference)
    for field in ("raw_text", "title"):
        values = [merged.get(field), fragment.get(field)]
        values = [" ".join(value.split()) for value in values if value]
        merged[field] = " ".join(dict.fromkeys(values)) or None

    for field in ("authors", "publication_name", "doi", "publication_year"):
        if not merged.get(field) and fragment.get(field):
            merged[field] = fragment[field]

    merged["text_end_offset"] = fragment.get("text_end_offset") or merged.get("text_end_offset")
    merged["locations"] = merged.get("locations", []) + fragment.get("locations", [])
    return merged


def is_reference_fragment(reference: dict[str, Any], primary: dict[str, Any]) -> bool:
    raw_text = " ".join((reference.get("raw_text") or "").split())
    if not raw_text or len(raw_text) > 180:
        return False
    if raw_text.casefold().strip(" .:-") in {
        "daftar pustaka",
        "references",
        "bibliography",
        "saran",
        "kesimpulan",
    }:
        return False
    publication_name = reference.get("publication_name")
    title = reference.get("title")
    if any(reference.get(field) for field in ("doi", "authors")):
        return False
    if publication_name and title and publication_name.casefold() != title.casefold():
        return False
    if not reference.get("title"):
        return False
    if re.search(r"\([A-Za-z][A-Za-z ]{2,}\)", reference.get("title") or ""):
        return False
    primary_locations = primary.get("locations") or []
    fragment_locations = reference.get("locations") or []
    if not primary_locations or not fragment_locations:
        return False
    first = primary_locations[-1]
    second = fragment_locations[0]
    return (
        first["page_number"] == second["page_number"]
        and second["y"] - (first["y"] + first["height"]) <= 48
    )


def parse_tei(tei_xml: str, pdf_bytes: bytes | None = None) -> dict[str, Any]:
    try:
        root = ET.fromstring(tei_xml)
    except ET.ParseError as exc:
        raise TEIParseError("Invalid TEI XML returned by GROBID") from exc

    text_element = root.find(".//tei:text", TEI_NS)
    full_text = clean_text(text_element) or ""
    body_element = root.find(".//tei:text/tei:body", TEI_NS)
    body_text = clean_text(body_element) or full_text
    page_sizes = extract_page_sizes(root)
    header = extract_header(root)

    bibl_structs = root.findall(".//tei:text/tei:back//tei:listBibl/tei:biblStruct", TEI_NS)
    if not bibl_structs:
        bibl_structs = root.findall(".//tei:listBibl/tei:biblStruct", TEI_NS)
    references: list[dict[str, Any]] = []
    reference_indices: dict[str, int] = {}
    cursor = 0
    index = 0
    while index < len(bibl_structs):
        bibl = bibl_structs[index]
        candidate, next_cursor = parse_reference(bibl, len(references), full_text, cursor, page_sizes)
        if not is_bibliography_entry(candidate):
            index += 1
            continue

        if index + 1 < len(bibl_structs):
            fragment, fragment_cursor = parse_reference(
                bibl_structs[index + 1], len(references), full_text, next_cursor, page_sizes
            )
            if is_reference_fragment(fragment, candidate):
                candidate = merge_reference_parts(candidate, fragment)
                next_cursor = fragment_cursor
                index += 1

        cursor = next_cursor
        references.append(candidate)
        if candidate.get("xml_id"):
            reference_indices[candidate["xml_id"]] = candidate["reference_index"]
        index += 1

    citations = extract_citations(root, body_text, page_sizes, reference_indices)
    if pdf_bytes:
        author_years: set[tuple[str, int]] = set()
        for reference in references:
            authors = reference.get("authors") or ""
            year = reference.get("publication_year")
            if year is None:
                continue
            first_author = re.split(r",|;| and | dan | & ", authors, maxsplit=1, flags=re.IGNORECASE)[0]
            author_tokens = first_author.split()
            surname = author_tokens[-1].strip(".,") if author_tokens else ""
            if surname:
                author_years.add((surname, int(year)))

        fallback_citations = extract_pdf_citations(pdf_bytes, author_years)
        citations = merge_citations(citations, fallback_citations)
    for reference in references:
        reference.pop("xml_id", None)

    return {
        "header": header,
        "references": references,
        "citations": citations,
    }
