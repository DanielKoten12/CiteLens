import re
import xml.etree.ElementTree as ET
from typing import Any

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
    analytic = bibl.find("tei:analytic", TEI_NS)
    monogr = bibl.find("tei:monogr", TEI_NS)
    title = find_first(analytic, [
        "tei:title[@level='a']",
        "tei:title[@type='main']",
        "tei:title",
    ])
    if title is None:
        title = find_first(monogr, ["tei:title[@level='m']", "tei:title[@level='j']", "tei:title"])
    return clean_text(title)


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
        target = (ref.get("target") or "").strip().split()
        target_id = target[0].lstrip("#") if target else None
        citations.append({
            "citation_text": citation_text,
            "citation_marker": citation_text,
            "context_before": full_text[max(0, start - 120):start] if start is not None else None,
            "context_after": full_text[end:min(len(full_text), end + 120)] if end is not None else None,
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


def parse_tei(tei_xml: str) -> dict[str, Any]:
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

    bibl_structs = root.findall(".//tei:listBibl/tei:biblStruct", TEI_NS)
    references: list[dict[str, Any]] = []
    reference_indices: dict[str, int] = {}
    cursor = 0
    for index, bibl in enumerate(bibl_structs):
        reference, cursor = parse_reference(bibl, index, full_text, cursor, page_sizes)
        references.append(reference)
        if reference.get("xml_id"):
            reference_indices[reference["xml_id"]] = index

    citations = extract_citations(root, body_text, page_sizes, reference_indices)
    for reference in references:
        reference.pop("xml_id", None)

    return {
        "header": header,
        "references": references,
        "citations": citations,
    }
