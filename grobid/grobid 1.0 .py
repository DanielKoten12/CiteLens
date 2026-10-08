import requests
import xml.etree.ElementTree as ET
import json
import re
from pathlib import Path


# ============================================================
# CONFIGURATION
# ============================================================

GROBID_URL = "http://localhost:8070"

PDF_FILE = "artikel.pdf"

OUTPUT_TEI = "artikel.references.tei.xml"
OUTPUT_JSON = "references.json"

TEI_NS = {
    "tei": "http://www.tei-c.org/ns/1.0"
}

XML_NS = "http://www.w3.org/XML/1998/namespace"


# ============================================================
# 1. SEND PDF TO GROBID /api/processReferences
# ============================================================

def process_references(pdf_file):
    """
    Upload PDF ke GROBID /api/processReferences
    dan mengembalikan TEI XML sebagai string.
    """

    url = f"{GROBID_URL}/api/processReferences"

    pdf_path = Path(pdf_file)

    if not pdf_path.exists():
        raise FileNotFoundError(
            f"File tidak ditemukan: {pdf_path}"
        )

    print(f"[INFO] Upload PDF: {pdf_path}")

    with open(pdf_path, "rb") as f:

        files = {
            "input": (
                pdf_path.name,
                f,
                "application/pdf"
            )
        }

        response = requests.post(
            url,
            files=files,
            timeout=300
        )

    print(
        f"[INFO] HTTP status: {response.status_code}"
    )

    if response.status_code != 200:
        raise RuntimeError(
            "GROBID gagal memproses PDF.\n"
            f"Status: {response.status_code}\n"
            f"Response: {response.text}"
        )

    tei_xml = response.text

    if not tei_xml.strip():
        raise RuntimeError(
            "GROBID mengembalikan TEI XML kosong."
        )

    print(
        f"[INFO] TEI XML diterima "
        f"({len(tei_xml):,} characters)"
    )

    return tei_xml


# ============================================================
# 2. SAVE TEI XML
# ============================================================

def save_tei(tei_xml, output_file):

    with open(
        output_file,
        "w",
        encoding="utf-8"
    ) as f:

        f.write(tei_xml)

    print(
        f"[INFO] TEI disimpan: {output_file}"
    )


# ============================================================
# 3. XML TEXT CLEANER
# ============================================================

def clean_text(element):

    if element is None:
        return None

    text = " ".join(
        "".join(element.itertext()).split()
    )

    return text if text else None


# ============================================================
# 4. EXTRACT AUTHORS
# ============================================================

def get_authors(bibl):

    authors = []

    for author in bibl.findall(
        ".//tei:author",
        TEI_NS
    ):

        pers_name = author.find(
            "tei:persName",
            TEI_NS
        )

        if pers_name is None:
            continue

        forenames = []

        for forename in pers_name.findall(
            "tei:forename",
            TEI_NS
        ):

            value = clean_text(forename)

            if value:
                forenames.append(value)

        surname = clean_text(
            pers_name.find(
                "tei:surname",
                TEI_NS
            )
        )

        full_name = " ".join(
            forenames +
            ([surname] if surname else [])
        )

        authors.append({
            "forename": (
                " ".join(forenames)
                if forenames
                else None
            ),
            "surname": surname,
            "full_name": (
                full_name
                if full_name
                else None
            )
        })

    return authors


# ============================================================
# 5. EXTRACT TITLES
# ============================================================

def get_titles(bibl):

    article_title = None
    journal = None
    book_title = None

    analytic = bibl.find(
        "tei:analytic",
        TEI_NS
    )

    monogr = bibl.find(
        "tei:monogr",
        TEI_NS
    )

    # --------------------------------------------------------
    # ANALYTIC TITLE
    # --------------------------------------------------------

    if analytic is not None:

        title = analytic.find(
            "tei:title[@level='a']",
            TEI_NS
        )

        if title is None:

            title = analytic.find(
                "tei:title[@type='main']",
                TEI_NS
            )

        if title is None:

            title = analytic.find(
                "tei:title",
                TEI_NS
            )

        article_title = clean_text(title)

    # --------------------------------------------------------
    # MONOGRAPH TITLES
    # --------------------------------------------------------

    if monogr is not None:

        for title in monogr.findall(
            "tei:title",
            TEI_NS
        ):

            text = clean_text(title)

            if not text:
                continue

            level = title.get("level")

            if level == "j":
                journal = text

            elif level == "m":
                book_title = text

    return {
        "article_title": article_title,
        "journal": journal,
        "book_title": book_title
    }


# ============================================================
# 6. EXTRACT DOI / ARXIV / URL
# ============================================================

def get_identifiers(bibl):

    doi = None
    arxiv = None

    identifiers = []
    urls = []

    # --------------------------------------------------------
    # IDNO
    # --------------------------------------------------------

    for idno in bibl.findall(
        ".//tei:idno",
        TEI_NS
    ):

        value = clean_text(idno)

        if not value:
            continue

        id_type = idno.get("type")

        identifiers.append({
            "type": id_type,
            "value": value
        })

        # DOI
        if (
            id_type
            and id_type.lower() == "doi"
        ):
            doi = value

        # ArXiv
        if "arxiv" in value.lower():
            arxiv = value

    # --------------------------------------------------------
    # PTR / URL
    # --------------------------------------------------------

    for ptr in bibl.findall(
        ".//tei:ptr",
        TEI_NS
    ):

        target = ptr.get("target")

        if not target:
            continue

        urls.append(target)

        # DOI dari URL
        if (
            "doi.org/" in target.lower()
            and doi is None
        ):

            doi = re.split(
                r"doi\.org/",
                target,
                flags=re.IGNORECASE
            )[-1]

    return {
        "doi": doi,
        "arxiv": arxiv,
        "identifiers": identifiers,
        "urls": urls
    }


# ============================================================
# 7. EXTRACT PUBLICATION INFORMATION
# ============================================================

def get_publication_info(bibl):

    result = {
        "date": None,
        "year": None,
        "volume": None,
        "issue": None,
        "page_from": None,
        "page_to": None,
        "pages": None,
        "publisher": None
    }

    # --------------------------------------------------------
    # DATE
    # --------------------------------------------------------

    date = bibl.find(
        ".//tei:date",
        TEI_NS
    )

    if date is not None:

        result["date"] = (
            date.get("when")
            or clean_text(date)
        )

        when = date.get("when")

        if when:

            match = re.search(
                r"\d{4}",
                when
            )

            if match:
                result["year"] = match.group(0)

        else:

            text = clean_text(date)

            if text:

                match = re.search(
                    r"\b(19|20)\d{2}\b",
                    text
                )

                if match:
                    result["year"] = match.group(0)

    # --------------------------------------------------------
    # BIBLSCOPE
    # --------------------------------------------------------

    for scope in bibl.findall(
        ".//tei:biblScope",
        TEI_NS
    ):

        unit = (
            scope.get("unit")
            or ""
        ).lower()

        value = clean_text(scope)

        if unit == "volume":

            result["volume"] = value

        elif unit == "issue":

            result["issue"] = value

        elif unit in ("page", "pp"):

            page_from = scope.get("from")
            page_to = scope.get("to")

            result["page_from"] = (
                page_from or value
            )

            result["page_to"] = page_to

            if page_from and page_to:

                result["pages"] = (
                    f"{page_from}-{page_to}"
                )

            else:

                result["pages"] = value

    # --------------------------------------------------------
    # PUBLISHER
    # --------------------------------------------------------

    publisher = bibl.find(
        ".//tei:publisher",
        TEI_NS
    )

    result["publisher"] = clean_text(
        publisher
    )

    return result


# ============================================================
# 8. LOCATION
# ============================================================

def get_locations(bibl):

    locations = []

    for addr in bibl.findall(
        ".//tei:address",
        TEI_NS
    ):

        for line in addr.findall(
            "tei:addrLine",
            TEI_NS
        ):

            value = clean_text(line)

            if value:
                locations.append(value)

    return locations


# ============================================================
# 9. NOTES
# ============================================================

def get_notes(bibl):

    notes = []

    for note in bibl.findall(
        "tei:note",
        TEI_NS
    ):

        value = clean_text(note)

        if value:
            notes.append(value)

    return notes


# ============================================================
# 10. PARSE ONE REFERENCE
# ============================================================

def parse_reference(bibl, index):

    titles = get_titles(bibl)

    identifiers = get_identifiers(bibl)

    publication = get_publication_info(bibl)

    return {

        "reference_index": index,

        "xml_id": bibl.get(
            f"{{{XML_NS}}}id"
        ),

        "status": bibl.get(
            "status"
        ),

        "authors": get_authors(bibl),

        "article_title": titles[
            "article_title"
        ],

        "journal": titles[
            "journal"
        ],

        "book_title": titles[
            "book_title"
        ],

        "year": publication[
            "year"
        ],

        "date": publication[
            "date"
        ],

        "volume": publication[
            "volume"
        ],

        "issue": publication[
            "issue"
        ],

        "page_from": publication[
            "page_from"
        ],

        "page_to": publication[
            "page_to"
        ],

        "pages": publication[
            "pages"
        ],

        "publisher": publication[
            "publisher"
        ],

        "doi": identifiers[
            "doi"
        ],

        "arxiv": identifiers[
            "arxiv"
        ],

        "identifiers": identifiers[
            "identifiers"
        ],

        "urls": identifiers[
            "urls"
        ],

        "locations": get_locations(
            bibl
        ),

        "notes": get_notes(
            bibl
        )
    }


# ============================================================
# 11. PARSE TEI XML
# ============================================================

def parse_tei(tei_xml):

    root = ET.fromstring(
        tei_xml
    )

    references = []

    bibl_structs = root.findall(
        ".//tei:listBibl/tei:biblStruct",
        TEI_NS
    )

    print(
        f"[INFO] Ditemukan "
        f"{len(bibl_structs)} reference"
    )

    for index, bibl in enumerate(
        bibl_structs,
        start=1
    ):

        reference = parse_reference(
            bibl,
            index
        )

        references.append(
            reference
        )

    return references


# ============================================================
# 12. SAVE JSON
# ============================================================

def save_json(
    references,
    output_file
):

    with open(
        output_file,
        "w",
        encoding="utf-8"
    ) as f:

        json.dump(
            references,
            f,
            ensure_ascii=False,
            indent=2
        )

    print(
        f"[INFO] JSON: {output_file}"
    )

# ============================================================
# 14. MAIN PIPELINE
# ============================================================

def main():

    print("=" * 60)
    print("GROBID REFERENCE EXTRACTION")
    print("=" * 60)

    # --------------------------------------------------------
    # STEP 1
    # PDF -> GROBID -> TEI
    # --------------------------------------------------------

    tei_xml = process_references(
        PDF_FILE
    )

    # --------------------------------------------------------
    # STEP 2
    # Save raw TEI
    # --------------------------------------------------------

    save_tei(
        tei_xml,
        OUTPUT_TEI
    )

    # --------------------------------------------------------
    # STEP 3
    # TEI -> metadata
    # --------------------------------------------------------

    references = parse_tei(
        tei_xml
    )

    # --------------------------------------------------------
    # STEP 4
    # JSON
    # --------------------------------------------------------

    save_json(
        references,
        OUTPUT_JSON
    )

    print("=" * 60)
    print("SELESAI")
    print("=" * 60)


if __name__ == "__main__":
    main()
