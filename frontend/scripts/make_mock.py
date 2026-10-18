"""Generate deterministic CiteLens viewer fixtures from public/sample.pdf."""

from __future__ import annotations

import json
import re
import uuid
from collections import Counter
from pathlib import Path

import pymupdf

ROOT = Path(__file__).resolve().parents[1]
PDF_PATH = ROOT / "public" / "sample.pdf"
MOCKS_PATH = ROOT / "src" / "mocks"
NAMESPACE = uuid.UUID("7e2cc3a4-4319-5c17-bc7f-1a4e6c1de5b1")


def stable_id(prefix: str, value: str) -> str:
    return f"{prefix}-{uuid.uuid5(NAMESPACE, value)}"


def locations_for(page: pymupdf.Page, text: str) -> list[dict]:
    rects = page.search_for(text)
    page_rect = page.rect
    return [
        {
            "page_number": page.number + 1,
            "x": round(rect.x0, 4),
            "y": round(rect.y0, 4),
            "width": round(rect.width, 4),
            "height": round(rect.height, 4),
            "page_width": round(page_rect.width, 4),
            "page_height": round(page_rect.height, 4),
            "coordinate_system": "pdf_points_top_left",
            "location_index": index,
        }
        for index, rect in enumerate(rects)
    ]


def find_text(document: pymupdf.Document, text: str) -> list[dict]:
    for page in document:
        locations = locations_for(page, text)
        if locations:
            return locations
    return []


def normalized_page_text(page: pymupdf.Page) -> tuple[str, list[int], list[tuple]]:
    words = page.get_text("words")
    words.sort(key=lambda word: (round(word[1], 2), word[0]))
    characters_to_words: list[int] = []
    parts: list[str] = []
    for word_index, word in enumerate(words):
        if parts:
            parts.append(" ")
            characters_to_words.append(word_index - 1)
        parts.append(word[4])
        characters_to_words.extend([word_index] * len(word[4]))
    return "".join(parts), characters_to_words, words


def citation_locations(
    page: pymupdf.Page,
    words: list[tuple],
    characters_to_words: list[int],
    match_start: int,
    match_end: int,
) -> list[dict]:
    covered_word_indexes = sorted(
        {
            characters_to_words[index]
            for index in range(match_start, match_end)
            if index < len(characters_to_words)
        }
    )
    lines: list[list[tuple]] = []
    for word_index in covered_word_indexes:
        word = words[word_index]
        line = next((line for line in lines if abs(line[0][1] - word[1]) <= 2.5), None)
        if line is None:
            lines.append([word])
        else:
            line.append(word)

    page_rect = page.rect
    locations: list[dict] = []
    for location_index, line in enumerate(sorted(lines, key=lambda group: min(word[1] for word in group))):
        x0 = min(word[0] for word in line)
        y0 = min(word[1] for word in line)
        x1 = max(word[2] for word in line)
        y1 = max(word[3] for word in line)
        locations.append(
            {
                "page_number": page.number + 1,
                "x": round(x0, 4),
                "y": round(y0, 4),
                "width": round(x1 - x0, 4),
                "height": round(y1 - y0, 4),
                "page_width": round(page_rect.width, 4),
                "page_height": round(page_rect.height, 4),
                "coordinate_system": "pdf_points_top_left",
                "location_index": location_index,
            }
        )
    return locations


def find_terms(document: pymupdf.Document, terms: list[str]) -> list[dict]:
    locations: list[dict] = []
    for term in terms:
        locations.extend(find_text(document, term))
    for index, location in enumerate(locations):
        location["location_index"] = index
    return locations


def write_json(name: str, data: list[dict]) -> None:
    (MOCKS_PATH / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    document = pymupdf.open(PDF_PATH)
    references_seed = [
        ("Dima", "Dima, J., Hamzah, Moh. S., Tallo, C. G., & Fallo, D. Y. A. (2025). Tinjauan Literatur tentang Pemanfaatan Algoritma Greedy untuk Pencarian Jalur Terpendek.", ["Dima, J., Hamzah, Moh. S., Tallo, C. G., & Fallo, D. Y. A. (2025). Tinjauan Literatur tentang Pemanfaatan Algoritma", "Greedy untuk Pencarian Jalur Terpendek."], "valid", "10.53863/kst.v7i01.1683"),
        ("Farhan", "Farhan Raihan Wahidin, Wina Witanti, & Edvin Ramadhan. (2025). Pengontrolan Lampu Lalu Lintas Menggunakan Teknologi Deteksi Kendaraan YOLOV4.", ["Farhan Raihan Wahidin, Wina Witanti, & Edvin Ramadhan. (2025). Pengontrolan Lampu Lalu Lintas Menggunakan", "Teknologi Deteksi Kendaraan YOLOV4."], "suspicious", "https://doi.org/10.25126/jtiik.2025125"),
        ("Febrianto", "Febrianto, M. F., Priyatno, A., Adisty, H., Saputri, A. F., Amanullah, R., Pebrianti Krissella, T., Irzavika, N., & Matondang, N. H. (2024). Prediksi Situasi Lalu Lintas Menggunakan Machine Learning Dengan Algoritma K-Nearest Neighbors Classifier.", ["Febrianto, M. F., Priyatno, A., Adisty, H., Saputri, A. F., Amanullah, R., Pebrianti Krissella, T., Irzavika, N., &", "Matondang, N. H. (2024). Prediksi Situasi Lalu Lintas Menggunakan Machine Learning Dengan Algoritma K-"], "valid", "10.52958/iftk.v20i1.7042"),
        ("Gullam", "Gullam Almuzadid, & Egia Rosi Subhiyakto. (2025). Stroke Risk Classification Using the Ensemble Learning Method of XGBoost and Random Forest.", ["Gullam Almuzadid, & Egia Rosi Subhiyakto. (2025). Stroke Risk Classification Using the Ensemble Learning Method"], "valid", "10.30871/jaic.v9i3.9528"),
        ("Kiswanto", "Kiswanto, D., Ramadhani, F., Surbakti, N. M., & Nasution, N. A. (2025). Pengembangan dan Implementasi Sistem Deteksi Serangan DDoS Berbasis Algoritma Random Forest.", ["Kiswanto, D., Ramadhani, F., Surbakti, N. M., & Nasution, N. A. (2025). Pengembangan dan Implementasi Sistem"], "suspicious", "10.9999/kiswanto-janggal"),
        ("Kurniawan", "Kurniawan, W. A., & Salam, M. K. A. (2025). Penggunaan Feature Space SMOTE Untuk Mengurangi Overfitting Akibat Imbalance Dataset.", ["Kurniawan, W. A., & Salam, M. K. A. (2025). Penggunaan Feature Space SMOTE Untuk Mengurangi Overfitting"], "valid", "10.26623/transformatika.v22i2.8305"),
        ("Kusuma", "Kusuma, M. R., Informasi, P. S., Mandiri, U., Sistem, P., Informasi, T., Darunnajah, U., Seleksi, F., & Learning, A. M. (2025). Pendekatan Analisis Prediktif Regresi Menggunakan Metode Pembelajaran Mesin Untuk Memperkirakan Effort.", ["Kusuma, M. R., Informasi, P. S., Mandiri, U., Sistem, P., Informasi, T., Darunnajah, U., Seleksi, F., &", "Learning, A. M. (2025). Pendekatan Analisis Prediktif Regresi Menggunakan Metode Pembelajaran Mesin Untuk Memperkirakan"], "pending", None),
        ("Mulyani", "Mulyani, S., & Arifin, T. (2025). Prediksi Kelangsungan Hidup Pasien Gagal Jantung Menggunakan Pendekatan Machine Learning dengan Optimasi GridSearchCV.", ["Mulyani, S., & Arifin, T. (2025). Prediksi Kelangsungan Hidup Pasien Gagal Jantung Menggunakan Pendekatan"], "valid", "10.33795/jip.v11i4.7938"),
        ("Niron", "Niron, W. P., Kuswara, K. M., Paul, D., & Tamelan, G. (n.d.). Faktor-Faktor Penyebab Kemacetan Lalu Lintas Di Ruas Jalan Herman Fernandez Larantuka Kabupaten Flores Timur.", ["Niron, W. P., Kuswara, K. M., Paul, D., & Tamelan, G. (n.d.). Faktor-Faktor Penyebab Kemacetan Lalu Lintas Di Ruas"], "invalid", "https://ejurnal.undana.ac.id/index.php/jurnal_teknologi/article/view/22338"),
        ("Pranadjaya", "Pranadjaya, E., Pangestu, E. S., Sereati, C. O., Octaviani, S., & Darmawan, M. (2024). Perbandingan Algoritma Machine Learning menggunakan Orange Data Mining untuk Klasifikasi Jenis Kendaraan pada Sistem Tilang Digital.", ["Pranadjaya, E., Pangestu, E. S., Sereati, C. O., Octaviani, S., & Darmawan, M. (2024). Perbandingan Algoritma"], "valid", "10.25170/jurnalelektro.v17i1.5429"),
        ("Pranata", "Pranata, J., Agustian, S., Jasril, J., & Haerani, E. (2024). Penggunaan Model Bahasa indoBERT pada metode Random Forest untuk Klasifikasi Sentimen dengan Dataset Terbatas.", ["Pranata, J., Agustian, S., Jasril, J., & Haerani, E. (2024). Penggunaan Model Bahasa indoBERT pada metode Random"], "valid", "10.47065/bits.v6i3.6335"),
        ("Rahayu", "Rahayu, S., Asmoro, B. R., & Rinal, E. (2023). Classification of Congestion in Jakarta Using KNN, Naïve Bayes and Decision Tree Method.", ["Rahayu, S., Asmoro, B. R., & Rinal, E. (2023). Classification of Congestion in Jakarta Using KNN, Naïve Bayes and"], "valid", "10.46799/jsa.v4i7.654"),
        ("Rodji", "Rodji, A. P., Studi, P., Ilmu, M., Nusa, U., Jakarta, M., Melayu, J. C., Timur, K. J., Ibukota, D. K., Sipil, S. T., Teknik, F., Krisnadwipayana, U., Gede, P., Bekasi, K., Tree, D., & Keparahan, K. (2025). Klasifikasi Keparahan Kecelakaan Lalu Lintas Menggunakan Machine Learning.", ["Rodji, A. P., Studi, P., Ilmu, M., Nusa, U., Jakarta, M., Melayu, J. C., Timur, K. J., Ibukota, D. K., Sipil, S. T., Teknik,", "Krisnadwipayana, U., Gede, P., Bekasi, K., Tree, D., & Keparahan, K. (2025). Klasifikasi Keparahan"], "not_found", None),
        ("Yao", "Yao, J., & Ye, Y. (2020). The effect of image recognition traffic prediction method under deep learning and naive Bayes algorithm on freeway traffic safety.", ["Yao, J., & Ye, Y. (2020). The effect of image recognition traffic prediction method under deep learning and naive"], "valid", "10.1016/j.imavis.2020.103971"),
        ("Zul", "Zul, Z., Al, H., & Subhiyakto, E. R. (2025). Analisis Komparatif Akurasi Prediksi Kanker Payudara Menggunakan Algoritma Random Forest dan Logistic Regression.", ["Zul, Z., Al, H., & Subhiyakto, E. R. (2025). Analisis Komparatif Akurasi Prediksi Kanker Payudara Menggunakan"], "valid", "10.33364/algoritma/v.22-1.2164"),
    ]
    references = []
    by_author: dict[str, dict] = {}
    for index, (author, raw_text, search_terms, status, doi) in enumerate(references_seed, 1):
        locations = find_terms(document, search_terms)
        if not locations:
            print(f"Tidak ditemukan di PDF: {raw_text}")
            continue
        reference_id = stable_id("ref", raw_text)
        reference = {
            "id": reference_id,
            "raw_text": raw_text,
            "doi": doi,
            "title": raw_text.split("). ", 1)[-1].rstrip("."),
            "authors": author,
            "publication_name": "Jurnal CiteLens",
            "publication_year": 2024 - (index % 6),
            "text_start_offset": (locations[0]["page_number"] - 1) * 10000 + index,
            "text_end_offset": (locations[0]["page_number"] - 1) * 10000 + index + len(raw_text),
            "finding": {
                "id": stable_id("finding", reference_id),
                "status": status,
                "confidence": {"valid": 0.96, "suspicious": 0.61, "invalid": 0.08, "not_found": None, "pending": None}[status],
                "reason": {
                    "valid": "DOI cocok dengan metadata Crossref.",
                    "suspicious": "DOI ditemukan, tetapi metadata Crossref perlu ditinjau.",
                    "invalid": "DOI tidak ditemukan di Crossref.",
                    "not_found": "Tidak ada kandidat Crossref yang cocok.",
                    "pending": "Menunggu verifikasi Crossref.",
                }[status],
                "selected_candidate_id": None,
            },
            "locations": locations,
        }
        references.append(reference)
        by_author[author] = reference

    citations = []
    citation_pattern = re.compile(r"\(([^()]{2,100}?),\s*(?:\d{4}|n\.d\.)\)")
    document_offset = 0
    occurrence_index = 0
    for page in document:
        page_text, characters_to_words, words = normalized_page_text(page)
        for match in citation_pattern.finditer(page_text):
            occurrence_index += 1
            text = re.sub(r"\s+", " ", match.group(0)).strip()
            author = text[1:].split(",", 1)[0].strip().split()[0]
            reference = next(
                (item for key, item in by_author.items() if key.lower().startswith(author.lower())),
                None,
            )
            locations = citation_locations(
                page,
                words,
                characters_to_words,
                match.start(),
                match.end(),
            )
            status = "hallucination" if text == "(Dima et al., 2025)" else {
                "valid": "valid",
                "suspicious": "valid",
                "invalid": "unreliable",
                "not_found": "unreliable",
                "pending": "pending",
            }[reference["finding"]["status"]] if reference else "hallucination"
            citation_id = stable_id("cit", f"{text}-{occurrence_index}")
            citations.append({
                "id": citation_id,
                "citation_text": text,
                "citation_marker": text[1:-1],
                "context_before": None,
                "context_after": None,
                "text_start_offset": document_offset + match.start(),
                "text_end_offset": document_offset + match.end(),
                "occurrence_index": occurrence_index,
                "status": status,
                "reference": (
                    None
                    if reference is None or status == "hallucination"
                    else {"id": reference["id"], "title": reference["title"]}
                ),
                "locations": locations,
            })
        document_offset += len(page_text) + 1

    findings = []
    for reference in references:
        status = reference["finding"]["status"]
        if status != "valid":
            findings.append({
                "id": f"ref-finding:{reference['id']}",
                "type": {
                    "suspicious": "reference_suspicious",
                    "invalid": "reference_invalid",
                    "not_found": "reference_not_found",
                    "pending": "reference_pending",
                }[status],
                "severity": {"suspicious": "medium", "invalid": "high", "not_found": "high", "pending": "info"}[status],
                "message": reference["finding"]["reason"],
                "reference_id": reference["id"],
                "citation_id": None,
                "text": reference["raw_text"],
                "start_offset": reference["text_start_offset"],
                "end_offset": reference["text_end_offset"],
                "locations": reference["locations"],
            })
    for citation in citations:
        if citation["status"] in {"unreliable", "hallucination"}:
            findings.append({
                "id": f"citation:{citation['id']}",
                "type": "citation_hallucination" if citation["status"] == "hallucination" else "citation_unreliable",
                "severity": "high",
                "message": "Sitasi tidak memiliki pasangan referensi yang dapat dipercaya.",
                "reference_id": None,
                "citation_id": citation["id"],
                "text": citation["citation_text"],
                "start_offset": citation["text_start_offset"],
                "end_offset": citation["text_end_offset"],
                "locations": citation["locations"],
            })
    findings.sort(key=lambda item: (item["start_offset"], item["id"]))

    write_json("references.json", references)
    write_json("citations.json", citations)
    write_json("findings.json", findings)
    print("Ringkasan referensi:", dict(Counter(item["finding"]["status"] for item in references)))
    print("Ringkasan sitasi:", dict(Counter(item["status"] for item in citations)))
    print(
        "Sitasi per halaman:",
        dict(Counter(item["locations"][0]["page_number"] for item in citations if item["locations"])),
    )
    print(
        "Referensi per halaman:",
        dict(
            Counter(
                page
                for item in references
                for page in {location["page_number"] for location in item["locations"]}
            )
        ),
    )
    print("Jumlah findings:", len(findings))


if __name__ == "__main__":
    main()
