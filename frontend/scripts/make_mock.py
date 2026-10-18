"""Generate deterministic CiteLens viewer fixtures from public/sample.pdf."""

from __future__ import annotations

import json
import re
import uuid
from collections import Counter
from pathlib import Path

import fitz

ROOT = Path(__file__).resolve().parents[1]
PDF_PATH = ROOT / "public" / "sample.pdf"
MOCKS_PATH = ROOT / "src" / "mocks"
NAMESPACE = uuid.UUID("7e2cc3a4-4319-5c17-bc7f-1a4e6c1de5b1")


def stable_id(prefix: str, value: str) -> str:
    return f"{prefix}-{uuid.uuid5(NAMESPACE, value)}"


def locations_for(page: fitz.Page, text: str) -> list[dict]:
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


def find_text(document: fitz.Document, text: str) -> list[dict]:
    for page in document:
        locations = locations_for(page, text)
        if locations:
            return locations
    return []


def find_terms(document: fitz.Document, terms: list[str]) -> list[dict]:
    locations: list[dict] = []
    for term in terms:
        locations.extend(find_text(document, term))
    for index, location in enumerate(locations):
        location["location_index"] = index
    return locations


def write_json(name: str, data: list[dict]) -> None:
    (MOCKS_PATH / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    document = fitz.open(PDF_PATH)
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

    citation_texts = re.findall(r"\([^()\n]{2,100},\s*(?:\d{4}|n\.d\.)\)", "\n".join(page.get_text() for page in document))
    citations = []
    for index, text in enumerate(citation_texts, 1):
        author = text[1:].split(",", 1)[0].strip().split()[0]
        reference = next((item for key, item in by_author.items() if key.lower().startswith(author.lower())), None)
        locations = find_text(document, text)
        if not locations:
            print(f"Tidak ditemukan di PDF: {text}")
            continue
        citation_id = stable_id("cit", f"{text}-{index}")
        status = "hallucination" if reference is None else {
            "valid": "valid",
            "suspicious": "valid",
            "invalid": "unreliable",
            "not_found": "unreliable",
            "pending": "pending",
        }[reference["finding"]["status"]]
        citations.append({
            "id": citation_id,
            "citation_text": text,
            "citation_marker": text[1:-1],
            "context_before": None,
            "context_after": None,
            "text_start_offset": (locations[0]["page_number"] - 1) * 10000 + index,
            "text_end_offset": (locations[0]["page_number"] - 1) * 10000 + index + len(text),
            "occurrence_index": index,
            "status": status,
            "reference": None if reference is None else {"id": reference["id"], "title": reference["title"]},
            "locations": locations,
        })

    dummy_id = stable_id("cit", "dummy-unpaired-citation")
    print("Tidak ditemukan di PDF: (Andi, 2022) [dummy hallucination]")
    citations.append({
        "id": dummy_id,
        "citation_text": "(Andi, 2022)",
        "citation_marker": "Andi, 2022",
        "context_before": None,
        "context_after": None,
        "text_start_offset": 999999,
        "text_end_offset": 999999 + len("(Andi, 2022)"),
        "occurrence_index": len(citations) + 1,
        "status": "hallucination",
        "reference": None,
        "locations": [],
    })

    findings = []
    for reference in references:
        status = reference["finding"]["status"]
        if status != "valid":
            findings.append({
                "id": f"ref-finding:{reference['id']}",
                "type": "reference_pending" if status == "pending" else "metadata_mismatch",
                "status": status,
                "severity": {"suspicious": "medium", "invalid": "high", "not_found": "high", "pending": "info"}[status],
                "reason": reference["finding"]["reason"],
                "reference_id": reference["id"],
                "text_start_offset": reference["text_start_offset"],
            })
    for citation in citations:
        if citation["status"] in {"unreliable", "hallucination"}:
            findings.append({
                "id": f"citation:{citation['id']}",
                "type": "citation_mismatch",
                "status": citation["status"],
                "severity": "high",
                "reason": "Sitasi tidak memiliki pasangan referensi yang dapat dipercaya.",
                "citation_id": citation["id"],
                "text_start_offset": citation["text_start_offset"],
            })
    findings.sort(key=lambda item: (item["text_start_offset"], item["id"]))

    write_json("references.json", references)
    write_json("citations.json", citations)
    write_json("findings.json", findings)
    print("Ringkasan referensi:", dict(Counter(item["finding"]["status"] for item in references)))
    print("Ringkasan sitasi:", dict(Counter(item["status"] for item in citations)))
    print("Jumlah findings:", len(findings))


if __name__ == "__main__":
    main()
