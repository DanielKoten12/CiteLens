# Spesifikasi API — Dafpus Cek

Ini adalah kontrak API antara frontend Vue.js dan backend Laravel. Layanan FastAPI adalah
**server inferensi internal saja** dan bukan bagian dari kontrak publik.

---

## 1. Arsitektur

```text
Vue.js (SPA)
   │  REST / JSON  (kontrak publik, dokumen ini)
   ▼
Laravel API  ── autentikasi, dokumen, referensi, sitasi, temuan, laporan, DB
   │
   ├── MySQL/Postgres  (lihat docs/DB_SCHEMA.md)
   ├── Crossref REST API (HTTP keluar, diorkestrasi oleh Laravel)
   ├── Queue worker  (pipeline analisis asinkron)
   └── Server inferensi FastAPI (internal, jaringan privat)
          ├── GROBID  → ekstraksi referensi + sitasi dalam teks + koordinat
          └── SBERT   → embedding kalimat
```

**Pembagian tanggung jawab**

| Aspek | Penanggung jawab |
|---|---|
| Autentikasi, persistensi, orkestrasi analisis, status/progres | Laravel |
| Pencarian Crossref, peringkat kandidat, skor, resolusi sitasi | Laravel |
| Kemiripan string (Jaro-Winkler, Levenshtein), pencocokan persis | Laravel |
| Parsing dokumen / ekstraksi referensi + sitasi (GROBID) | FastAPI (inferensi) |
| Embedding semantik (SBERT) | FastAPI (inferensi) |

Laravel adalah sumber kebenaran. Algoritma GROBID/SBERT **tidak pernah** diekspos sebagai endpoint
publik.

---

## 2. Konvensi

### 2.1 Base URL & versi

```text
Production : https://api.dafpus.example/api/v1
Local      : http://localhost:8000/api/v1
```

Semua endpoint di bawah ini relatif terhadap base URL dan memerlukan header
`Accept: application/json`.

### 2.2 Autentikasi

Bearer token melalui personal access token **Laravel Sanctum** (dependensi yang disetujui).

```http
Authorization: Bearer <token>
```

Token diterbitkan saat register/login dan dicabut saat logout. Semua endpoint kecuali
`POST /auth/register` dan `POST /auth/login` memerlukan autentikasi.

### 2.3 Tipe & format data

| Tipe | Format |
|---|---|
| ID | string UUID v4 |
| Timestamp | ISO 8601 UTC, mis. `2026-09-23T15:30:00Z` |
| Confidence | desimal `0.0000`–`1.0000` (frontend menampilkan sebagai %) |
| Nilai enum | lower_snake_case |

### 2.4 Envelope sukses

Satu resource:

```json
{
  "data": { "id": "…" },
  "message": "Optional human-readable note"
}
```

Koleksi:

```json
{
  "data": [ { "id": "…" } ],
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

`message` hanya ada pada aksi (create/update/delete/trigger). `meta` hanya ada pada koleksi yang
dipaginasi.

### 2.5 Envelope error

Setiap respons non-2xx memakai bentuk yang sama:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": { "file": ["The file must be a PDF."] }
  }
}
```

`details` bersifat opsional (ada untuk validasi dan kegagalan spesifik domain).

| HTTP | `code` | Kapan |
|---|---|---|
| 400 | `BAD_REQUEST` | Permintaan cacat |
| 401 | `UNAUTHENTICATED` | Token hilang/kedaluwarsa/tidak valid |
| 403 | `FORBIDDEN` | Aksi tidak diizinkan |
| 404 | `NOT_FOUND` | ID tidak dikenal atau bukan milik pemanggil |
| 409 | `CONFLICT` | Transisi state tidak valid (mis. analisis sedang berjalan) |
| 413 | `PAYLOAD_TOO_LARGE` | File melebihi batas ukuran |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | File bukan PDF |
| 422 | `VALIDATION_ERROR` | Validasi field gagal |
| 429 | `RATE_LIMITED` | Batas laju terlampaui |
| 500 | `SERVER_ERROR` | Error tidak tertangani |
| 503 | `INFERENCE_UNAVAILABLE` | GROBID/SBERT tidak dapat dijangkau atau analisis gagal |

### 2.6 Enum

**Status dokumen** (`researched_documents.status`) — himpunan yang disetujui

| Nilai | Arti |
|---|---|
| `pending` | Tersimpan, analisis belum dimulai (default skema) |
| `processing` | Pipeline sedang berjalan |
| `completed` | Pipeline selesai (meski ada temuan) |
| `failed` | Pipeline dibatalkan; lihat `analysis_error` |

**Langkah analisis** (`researched_documents.analysis_step`)

`queued` · `extracting` · `persisting` · `crossref_validation` · `embedding` · `scoring` ·
`resolving_citations` · `generating_report` · `completed`

**Status temuan referensi** (`reference_findings.status`)

| Nilai | Arti |
|---|---|
| `pending` | Belum dievaluasi |
| `valid` | Cocok dengan meyakinkan |
| `suspicious` | Cocok tetapi confidence rendah / sebagian tidak cocok |
| `invalid` | Kecocokan ditemukan tetapi metadata bertentangan |
| `not_found` | Tidak ada kandidat ditemukan di Crossref |

**Status sitasi** (derived, tidak disimpan — dihitung ulang dari pasangan dan temuan referensi)

| Nilai | Kondisi |
|---|---|
| `valid` | Berpasangan, dan status temuan referensi adalah `valid` atau `suspicious` |
| `unreliable` | Berpasangan, tetapi status temuan referensi adalah `invalid` atau `not_found` |
| `pending` | Berpasangan, tetapi temuan referensi masih `pending` |
| `hallucination` | Tidak ada pasangan referensi (`researched_document_reference_id` bernilai `null`) |

Karena status ini derived, mengubah temuan referensi (mis. melalui review manual) langsung mengubah
status setiap sitasi yang menunjuk padanya.

**Status laporan** (`generated_document_reports.status`)

`pending` · `processing` · `completed` · `failed`

**Severitas temuan** (derived)

`high` · `medium` · `low` · `info`

Pemetaan severitas:

| Temuan | Severitas |
|---|---|
| referensi `not_found` | high |
| referensi `invalid` | high |
| sitasi `hallucination` | high |
| sitasi `unreliable` | severitas temuan referensi pasangannya |
| referensi `suspicious` | medium |
| referensi `pending` | info |

### 2.7 Paginasi, filter, pengurutan

Koleksi menerima `page` dan `per_page` (default 15, maksimum 100). Filter daftar didokumentasikan
per endpoint. Filter tidak valid mengembalikan `422`. Urutan default adalah `created_at` menurun
kecuali dinyatakan lain.

### 2.8 Kepemilikan & penghapusan

Sebuah resource hanya dapat diakses oleh pemilik `researched_document` induknya. Mengakses resource
milik pengguna lain mengembalikan `404 NOT_FOUND` (jangan bocorkan keberadaannya).

Penghapusan adalah **hard delete** dengan cascade ke baris terkait (referensi, sitasi, lokasi,
temuan, kandidat, file, laporan).

### 2.9 Batas laju (default)

| Cakupan | Batas |
|---|---|
| `POST /auth/login`, `POST /auth/register` | 5 / menit / IP |
| `POST /documents` | 10 / menit / pengguna |
| `GET /documents/{document}/status` | 120 / menit / pengguna |
| Endpoint terautentikasi (umum) | 60 / menit / pengguna |

Melebihi batas mengembalikan `429`:

```json
{ "error": { "code": "RATE_LIMITED", "message": "Too many attempts. Please try again later." } }
```

---

## 3. Autentikasi — `/auth`

### POST `/auth/register`

**Permintaan**

```json
{
  "name": "Daniel Belawa Koten",
  "email": "daniel@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Aturan: `name` wajib string ≤ 255; `email` wajib, unik, valid; `password` wajib, dikonfirmasi,
minimal 8.

**Sukses — `201`**

```json
{
  "data": {
    "user": {
      "id": "9f1c2b3a-4d5e-4f60-8a91-2b3c4d5e6f70",
      "name": "Daniel Belawa Koten",
      "email": "daniel@example.com",
      "created_at": "2026-09-23T15:30:00Z"
    },
    "token": "1|eyJhbGciOi…",
    "token_type": "Bearer"
  },
  "message": "Registrasi berhasil."
}
```

**Gagal — `422`**

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email has already been taken."],
      "password": ["The password confirmation does not match."]
    }
  }
}
```

### POST `/auth/login`

**Permintaan**

```json
{ "email": "daniel@example.com", "password": "password123" }
```

**Sukses — `200`**

```json
{
  "data": {
    "user": { "id": "9f1c…", "name": "Daniel Belawa Koten", "email": "daniel@example.com" },
    "token": "2|eyJhbGciOi…",
    "token_type": "Bearer"
  }
}
```

**Gagal — `422`** (kredensial tidak valid)

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Kredensial tidak valid.",
    "details": { "email": ["These credentials do not match our records."] }
  }
}
```

**Gagal — `429`** (dibatasi) — lihat §2.9.

### POST `/auth/logout`

Mencabut token saat ini.

**Sukses — `200`**

```json
{ "data": null, "message": "Logout berhasil." }
```

**Gagal — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

### GET `/auth/me`

**Sukses — `200`**

```json
{ "data": { "id": "9f1c…", "name": "Daniel Belawa Koten", "email": "daniel@example.com", "created_at": "2026-09-23T15:30:00Z" } }
```

**Gagal — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

---

## 4. Dokumen — `/documents`

Upload **dan** memulai analisis digabung: `POST /documents` menyimpan dokumen, melampirkan file,
dan otomatis menjalankan pipeline analisis.

### POST `/documents`

`Content-Type: multipart/form-data`

| Field | Tipe | Wajib | Catatan |
|---|---|---|---|
| `file` | file | ya | **Hanya PDF** (DOCX tidak didukung di v1), ≤ 20 MB |
| `name` | string | tidak | Default-nya nama file asli |

**Perilaku**

1. Simpan file yang diunggah dan buat `researched_documents` (status `pending`) + record `files`.
2. Dispatch `AnalyzeDocumentJob` ke queue.
3. Respons langsung; analisis berjalan asinkron (polling `GET /documents/{id}/status`).

**Sukses — `202 Accepted`**

```json
{
  "data": {
    "id": "b2d4e6f8-1234-4abc-9def-0123456789ab",
    "name": "laporan-akhir.pdf",
    "status": "pending",
    "progress": 0,
    "current_step": "queued",
    "error": null,
    "file": {
      "filename": "laporan-akhir.pdf",
      "mime_type": "application/pdf",
      "size": 245678,
      "url": "https://api.dafpus.example/storage/documents/b2d4…/laporan-akhir.pdf"
    },
    "created_at": "2026-09-23T15:30:00Z",
    "updated_at": "2026-09-23T15:30:00Z"
  },
  "message": "Dokumen berhasil diunggah. Analisis sedang diproses."
}
```

**Gagal — `422` / `413` / `415` / `503`**

```json
{
  "error": {
    "code": "UNSUPPORTED_MEDIA_TYPE",
    "message": "Format file tidak didukung. Hanya PDF yang diterima.",
    "details": { "file": ["The file must be a file of type: pdf."] }
  }
}
```

```json
{
  "error": {
    "code": "PAYLOAD_TOO_LARGE",
    "message": "Ukuran file melebihi batas 20 MB.",
    "details": { "file": ["The file may not be greater than 20480 kilobytes."] }
  }
}
```

```json
{
  "error": {
    "code": "INFERENCE_UNAVAILABLE",
    "message": "Layanan analisis tidak tersedia. Coba lagi nanti."
  }
}
```

### GET `/documents`

Daftar riwayat. Filter: `status`, `q` (pencarian nama), `sort` (`created_at|-created_at`, default
`-created_at`).

**Sukses — `200`**

```json
{
  "data": [
    {
      "id": "b2d4…",
      "name": "laporan-akhir.pdf",
      "status": "completed",
      "progress": 100,
      "summary": {
        "total_references": 8,
        "valid": 5,
        "suspicious": 2,
        "invalid": 0,
        "not_found": 1,
        "total_citations": 15,
        "valid_citations": 12,
        "unreliable_citations": 1,
        "pending_citations": 0,
        "hallucination_citations": 2
      },
      "created_at": "2026-09-23T15:30:00Z",
      "updated_at": "2026-09-23T15:31:12Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

**Gagal — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

### GET `/documents/{document}`

Detail dokumen lengkap (bentuk sama seperti di atas; `summary` ada setelah `status = completed`).

**Sukses — `200`** · **Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/documents/{document}/status`

Endpoint polling ringan. Interval yang disarankan untuk klien: **setiap 2 detik**, melambat ke
**5 detik** setelah 30 detik; berhenti saat `completed` atau `failed`.

**Sukses — `200`**

```json
{
  "data": {
    "id": "b2d4…",
    "status": "processing",
    "progress": 65,
    "current_step": "crossref_validation",
    "error": null,
    "updated_at": "2026-09-23T15:31:05Z"
  }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### POST `/documents/{document}/retry`

Menjalankan ulang pipeline untuk dokumen `failed`. Hanya diizinkan saat `status = failed`.

**Sukses — `202`**

```json
{ "data": { "id": "b2d4…", "status": "pending", "progress": 0, "current_step": "queued" }, "message": "Analisis dijadwalkan ulang." }
```

**Gagal — `409`**

```json
{
  "error": {
    "code": "CONFLICT",
    "message": "Analisis hanya dapat diulang untuk dokumen yang gagal."
  }
}
```

### DELETE `/documents/{document}`

Hard delete satu dokumen dan cascade ke referensi, sitasi, lokasi, temuan, kandidat, file, dan
laporannya.

**Sukses — `204`** (tanpa body) · **Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### DELETE `/documents`

Hard delete seluruh riwayat milik pengguna yang terautentikasi.

**Sukses — `204`** (tanpa body)

---

## 5. Referensi — `/references`

*Referensi* adalah entri daftar pustaka (`researched_document_references`). Verdikt review-nya
tersimpan di `reference_findings`; kandidatnya di `reference_finding_candidates`.

### GET `/documents/{document}/references`

Filter: `status` (status temuan), `has_doi` (bool). Dipaginasi.

**Sukses — `200`**

```json
{
  "data": [
    {
      "id": "r-101-uuid",
      "raw_text": "LeCun, Y., Bengio, Y., & Hinton, G. (2019). Deep learning. Nature.",
      "doi": "10.1038/nature14539",
      "title": "Deep learning",
      "authors": "LeCun, Y., Bengio, Y., & Hinton, G.",
      "publication_name": "Nature",
      "publication_year": 2019,
      "text_start_offset": 8120,
      "text_end_offset": 8295,
      "finding": {
        "id": "f-101-uuid",
        "status": "valid",
        "confidence": 0.9500,
        "reason": "Exact DOI match with Crossref.",
        "selected_candidate_id": "c-101-uuid"
      }
    },
    {
      "id": "r-102-uuid",
      "raw_text": "Koten, D. B. (2023). Sistem Deteksi Plagiarisme. Jurnal Informatika.",
      "doi": null,
      "title": "Sistem Deteksi Plagiarisme",
      "authors": "Koten, D. B.",
      "publication_name": "Jurnal Informatika",
      "publication_year": 2023,
      "text_start_offset": 8300,
      "text_end_offset": 8390,
      "finding": {
        "id": "f-102-uuid",
        "status": "suspicious",
        "confidence": 0.6300,
        "reason": "Judul pada metadata Crossref memiliki perbedaan.",
        "selected_candidate_id": "c-102-uuid"
      }
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 8, "last_page": 1 }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/references/{reference}`

Mencakup temuan, kandidat yang diperingkat, lokasi sorotan, dan sitasi teresolusi yang menunjuk ke
referensi ini.

**Sukses — `200`**

```json
{
  "data": {
    "id": "r-102-uuid",
    "raw_text": "Koten, D. B. (2023). Sistem Deteksi Plagiarisme. Jurnal Informatika.",
    "doi": null,
    "title": "Sistem Deteksi Plagiarisme",
    "authors": "Koten, D. B.",
    "publication_name": "Jurnal Informatika",
    "publication_year": 2023,
    "text_start_offset": 8300,
    "text_end_offset": 8390,
    "locations": [
      {
        "page_number": 12,
        "x": 72.0,
        "y": 210.4,
        "width": 451.2,
        "height": 24.0,
        "page_width": 595.0,
        "page_height": 842.0,
        "coordinate_system": "pdf_points_top_left",
        "location_index": 0
      }
    ],
    "finding": {
      "id": "f-102-uuid",
      "status": "suspicious",
      "confidence": 0.6300,
      "reason": "Judul pada metadata Crossref memiliki perbedaan.",
      "selected_candidate_id": "c-102-uuid",
      "is_manual": false,
      "reviewed_by": null,
      "reviewed_at": null,
      "candidates": [
        {
          "id": "c-102-uuid",
          "rank": 1,
          "confidence": 0.6300,
          "doi": "10.1234/example",
          "title": "Sistem Pendeteksi Plagiarisme Dokumen",
          "authors": "Koten, D. B.",
          "publication_name": "Jurnal Informatika",
          "publication_year": 2023,
          "url": "https://doi.org/10.1234/example",
          "match_reason": "Author and year match; title similarity 0.78."
        }
      ]
    },
    "citations": [
      { "id": "cit-201-uuid", "citation_text": "(Koten, 2023)", "occurrence_index": 4 }
    ]
  }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Referensi tidak ditemukan." } }
```

### PATCH `/references/{reference}/finding`

Override review manual. Memungkinkan pengguna mengubah verdikt dan/atau memilih kandidat lain.
Menetapkan `is_manual = true` serta mengisi `reviewed_by` / `reviewed_at`.

**Permintaan**

```json
{
  "status": "valid",
  "selected_candidate_id": "c-102-uuid",
  "reason": "Diverifikasi manual oleh pengguna."
}
```

Aturan: `status` wajib, salah satu dari `valid|suspicious|invalid|not_found`;
`selected_candidate_id` nullable, harus milik temuan ini; `reason` string nullable.

**Sukses — `200`**

```json
{
  "data": {
    "id": "f-102-uuid",
    "status": "valid",
    "confidence": 0.6300,
    "reason": "Diverifikasi manual oleh pengguna.",
    "selected_candidate_id": "c-102-uuid",
    "is_manual": true,
    "reviewed_by": "9f1c…",
    "reviewed_at": "2026-09-23T15:40:00Z",
    "updated_at": "2026-09-23T15:40:00Z"
  },
  "message": "Status referensi diperbarui."
}
```

**Gagal — `422` / `404`**

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": { "status": ["The selected status is invalid."] }
  }
}
```

---

## 6. Sitasi — `/citations`

*Sitasi* adalah satu kemunculan dalam teks (`researched_document_citations`). Satu referensi bisa
punya banyak baris sitasi. Status sitasi bersifat **derived** (§2.6): sitasi tanpa pasangan
ditandai **`hallucination`**, dan sitasi yang berpasangan dengan referensi `invalid`/`not_found`
ditandai **`unreliable`** (referensi `suspicious` tetap menghasilkan sitasi `valid`). Setiap sitasi
dapat punya beberapa lokasi sorotan (`researched_document_citation_locations`).

### GET `/documents/{document}/citations`

Filter: `status` (`valid|unreliable|pending|hallucination`), `reference_id`. Dipaginasi.

**Sukses — `200`**

```json
{
  "data": [
    {
      "id": "cit-201-uuid",
      "citation_text": "(Koten, 2023)",
      "citation_marker": "Koten, 2023",
      "context_before": "…seperti yang dijelaskan oleh ",
      "context_after": " dalam penelitiannya.",
      "text_start_offset": 2150,
      "text_end_offset": 2162,
      "occurrence_index": 4,
      "status": "valid",
      "reference": { "id": "r-102-uuid", "title": "Sistem Deteksi Plagiarisme" }
    },
    {
      "id": "cit-203-uuid",
      "citation_text": "(Hidayat et al., 2021)",
      "citation_marker": "Hidayat et al., 2021",
      "context_before": null,
      "context_after": null,
      "text_start_offset": 3300,
      "text_end_offset": 3322,
      "occurrence_index": 7,
      "status": "unreliable",
      "reference": { "id": "r-103-uuid", "title": "Analisis Sentimen Berita" }
    },
    {
      "id": "cit-202-uuid",
      "citation_text": "(Andi, 2022)",
      "citation_marker": "Andi, 2022",
      "context_before": null,
      "context_after": null,
      "text_start_offset": 4520,
      "text_end_offset": 4532,
      "occurrence_index": 9,
      "status": "hallucination",
      "reference": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 15, "last_page": 1 }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/citations/{citation}`

**Sukses — `200`**

```json
{
  "data": {
    "id": "cit-202-uuid",
    "citation_text": "(Andi, 2022)",
    "citation_marker": "Andi, 2022",
    "context_before": null,
    "context_after": null,
    "text_start_offset": 4520,
    "text_end_offset": 4532,
    "occurrence_index": 9,
    "status": "hallucination",
    "reference": null,
    "locations": [
      {
        "page_number": 3,
        "x": 120.5,
        "y": 640.2,
        "width": 48.0,
        "height": 11.0,
        "page_width": 595.0,
        "page_height": 842.0,
        "coordinate_system": "pdf_points_top_left",
        "location_index": 0
      }
    ]
  }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Sitasi tidak ditemukan." } }
```

### PATCH `/citations/{citation}`

Memasangkan sitasi `hallucination` dengan referensi secara manual, atau menghapus pasangannya.

**Permintaan**

```json
{ "researched_document_reference_id": "r-102-uuid" }
```

Aturan: field nullable; jika ada, referensi harus berada dalam dokumen yang sama.

**Sukses — `200`**

```json
{
  "data": {
    "id": "cit-202-uuid",
    "status": "valid",
    "reference": { "id": "r-102-uuid", "title": "Sistem Deteksi Plagiarisme" }
  },
  "message": "Sitasi berhasil ditautkan."
}
```

**Gagal — `422` / `404`**

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": {
      "researched_document_reference_id": ["The selected reference is invalid for this document."]
    }
  }
}
```

---

## 7. Temuan / Sorotan — `/findings`

Feed read-only yang bersifat derived, menggabungkan temuan referensi bermasalah, sitasi
`unreliable`, dan sitasi `hallucination`. Digunakan oleh penampil dokumen untuk merender daftar
masalah dan sorotan.

### GET `/documents/{document}/findings`

Filter: `type` (`reference_invalid|reference_suspicious|reference_not_found|citation_unreliable|citation_hallucination`),
`severity` (`high|medium|low|info`). Dipaginasi.

**Sukses — `200`**

```json
{
  "data": [
    {
      "id": "ref-finding:f-102-uuid",
      "type": "reference_suspicious",
      "severity": "medium",
      "message": "Judul pada metadata Crossref memiliki perbedaan.",
      "reference_id": "r-102-uuid",
      "citation_id": null,
      "text": "Koten, D. B. (2023). Sistem Deteksi Plagiarisme. Jurnal Informatika.",
      "start_offset": 8300,
      "end_offset": 8390,
      "locations": [
        { "page_number": 12, "x": 72.0, "y": 210.4, "width": 451.2, "height": 24.0, "location_index": 0 }
      ]
    },
    {
      "id": "citation:cit-203-uuid",
      "type": "citation_unreliable",
      "severity": "high",
      "message": "Sitasi merujuk pada referensi yang tidak berhasil diverifikasi (invalid/not_found).",
      "reference_id": "r-103-uuid",
      "citation_id": "cit-203-uuid",
      "text": "(Hidayat et al., 2021)",
      "start_offset": 3300,
      "end_offset": 3322,
      "locations": [
        { "page_number": 5, "x": 88.0, "y": 410.0, "width": 96.0, "height": 11.0, "location_index": 0 }
      ]
    },
    {
      "id": "citation:cit-202-uuid",
      "type": "citation_hallucination",
      "severity": "high",
      "message": "Sitasi tidak memiliki pasangan referensi (hallucination).",
      "reference_id": null,
      "citation_id": "cit-202-uuid",
      "text": "(Andi, 2022)",
      "start_offset": 4520,
      "end_offset": 4532,
      "locations": [
        { "page_number": 3, "x": 120.5, "y": 640.2, "width": 48.0, "height": 11.0, "location_index": 0 }
      ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 6, "last_page": 1 }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

---

## 8. Laporan — `/reports`

Didukung oleh `generated_document_reports`. Satu laporan per dokumen, dibuat secara asinkron dalam
format **hanya PDF** (tanpa ekspor DOCX/Excel di v1). Lihat `docs/DB_SCHEMA.md` untuk skema
lengkapnya.

### POST `/documents/{document}/reports`

**Perilaku**: membuat baris laporan (`status = pending`), dispatch job pembuatan, lalu merespons
langsung. Hanya diizinkan saat dokumen `status = completed`.

**Sukses — `202`**

```json
{
  "data": { "id": "rep-uuid", "document_id": "b2d4…", "status": "pending", "generated_at": null },
  "message": "Laporan sedang dibuat."
}
```

**Gagal — `409`**

```json
{
  "error": {
    "code": "CONFLICT",
    "message": "Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis."
  }
}
```

### GET `/documents/{document}/reports`

**Sukses — `200`**

```json
{
  "data": [
    {
      "id": "rep-uuid",
      "document_id": "b2d4…",
      "status": "completed",
      "download_url": "https://api.dafpus.example/storage/reports/rep-uuid.pdf",
      "generated_at": "2026-09-23T15:45:00Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1 }
}
```

### GET `/reports/{report}`

**Sukses — `200`**

```json
{
  "data": {
    "id": "rep-uuid",
    "document_id": "b2d4…",
    "status": "completed",
    "download_url": "https://api.dafpus.example/storage/reports/rep-uuid.pdf",
    "error": null,
    "generated_at": "2026-09-23T15:45:00Z"
  }
}
```

**Gagal — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Laporan tidak ditemukan." } }
```

### DELETE `/reports/{report}`

Hard delete laporan beserta file tersimpannya.

**Sukses — `204`** (tanpa body) · **Gagal — `404`**

---

## 9. API Inferensi Internal (FastAPI — bukan publik)

Hanya dipanggil oleh queue worker Laravel. Berjalan di **jaringan privat saja**; tanpa autentikasi
di v1.

Base URL: `http://inference:8000` (internal).

### GET `/health`

**Sukses — `200`**: `{ "status": "ok", "grobid": "up", "sbert": "up" }`

### POST `/v1/extract` — GROBID

`multipart/form-data` dengan `file` (**hanya PDF**). Mengembalikan header terekstraksi, referensi,
sitasi dalam teks, dan koordinat.

**Sukses — `200`**

```json
{
  "data": {
    "references": [
      {
        "raw_text": "LeCun, Y., …",
        "title": "Deep learning",
        "authors": "LeCun, Y., Bengio, Y., & Hinton, G.",
        "publication_name": "Nature",
        "publication_year": 2019,
        "doi": "10.1038/nature14539",
        "text_start_offset": 8120,
        "text_end_offset": 8295,
        "locations": [
          { "page_number": 12, "x": 72.0, "y": 210.4, "width": 451.2, "height": 24.0, "page_width": 595.0, "page_height": 842.0 }
        ]
      }
    ],
    "citations": [
      {
        "citation_text": "(LeCun et al., 2019)",
        "citation_marker": "LeCun et al., 2019",
        "context_before": "…",
        "context_after": "…",
        "text_start_offset": 2150,
        "text_end_offset": 2162,
        "occurrence_index": 0,
        "reference_index": 0,
        "locations": [
          { "page_number": 3, "x": 120.5, "y": 640.2, "width": 48.0, "height": 11.0, "page_width": 595.0, "page_height": 842.0 }
        ]
      }
    ]
  }
}
```

**Gagal — `422`** (PDF tidak dapat diparse) · **`503`** (GROBID mati)

```json
{ "error": { "code": "EXTRACTION_FAILED", "message": "GROBID could not process the document." } }
```

### POST `/v1/embeddings` — SBERT

**Permintaan**

```json
{ "texts": ["Deep learning", "Sistem Deteksi Plagiarisme"], "model": "sbert" }
```

**Sukses — `200`**

```json
{ "data": { "model": "sbert", "dimensions": 768, "embeddings": [[0.021, -0.133, "…"], [0.004, 0.088, "…"]] } }
```

**Gagal — `422`** (`texts` kosong) · **`503`** (model tidak tersedia)

```json
{ "error": { "code": "INFERENCE_FAILED", "message": "SBERT model is unavailable." } }
```

> Batch permintaan `/v1/embeddings` (mis. 32 teks) untuk membatasi latensi dan memori.

---

## 10. Pipeline analisis (diorkestrasi oleh Laravel)

Dipicu oleh `POST /documents`.

```text
1. Simpan file PDF (files) → buat researched_documents (status=pending, analysis_step=queued)
2. FastAPI POST /v1/extract (GROBID)
      → insert researched_document_references (+ offset teks)
      → insert researched_document_reference_locations
      → insert researched_document_citations
      → insert researched_document_citation_locations
3. Untuk setiap referensi:
      → pencarian Crossref (DOI atau pencarian bibliografis)
      → insert reference_finding_candidates (diperingkat)
4. Skor kemiripan (Laravel): Jaro-Winkler + Levenshtein + embedding SBERT
      → hitung confidence, pilih selected_candidate_id
      → upsert reference_findings (status, reason)
5. Resolve sitasi → set researched_document_citations.researched_document_reference_id
      → sitasi tanpa pasangan ditandai status = hallucination
6. Hitung ringkasan → researched_documents.status=completed
   Jika ada error tak terpulihkan → status=failed + analysis_error
```

Progres dilaporkan melalui `analysis_progress` / `analysis_step` / `analysis_error` pada
`researched_documents`.

---

## 11. Pemetaan skema

| Konsep API | Tabel / kolom |
|---|---|
| Dokumen | `researched_documents` (`id`, `user_id`, `name`, `status`) |
| File dokumen | `files` (polimorfik via `fileable_type`, `fileable_id`) |
| Status/progres dokumen | `researched_documents.status` + `analysis_progress`, `analysis_step`, `analysis_error`, `analysis_started_at`, `analysis_completed_at` |
| Referensi | `researched_document_references` (`raw_text`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, `text_start_offset`, `text_end_offset`) |
| Sorotan referensi | `researched_document_reference_locations` |
| Verdikt referensi | `reference_findings` (`status`, `confidence`, `reason`, `selected_candidate_id`, `is_manual`, `reviewed_by`, `reviewed_at`) |
| Kandidat referensi | `reference_finding_candidates` (`rank`, `confidence`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, `url`, `match_reason`) |
| Sitasi | `researched_document_citations` (`citation_text`, `citation_marker`, `context_*`, `text_*_offset`, `occurrence_index`, `researched_document_reference_id`) |
| Status sitasi | **derived**: `hallucination` jika tanpa pasangan; selain itu `valid` / `unreliable` / `pending` dari status temuan referensi pasangannya |
| Sorotan sitasi | `researched_document_citation_locations` (`page_number`, `x`, `y`, `width`, `height`, `page_*`, `coordinate_system`, `location_index`) |
| Feed temuan | derived dari `reference_findings` + sitasi `hallucination` |
| Laporan | `generated_document_reports` (+ `status`, `file_id` nullable, `generated_at` nullable) |

---

## 12. Ringkasan endpoint

| Metode | Endpoint | Fungsi |
|---|---|---|
| POST | `/auth/register` | Registrasi + terbitkan token |
| POST | `/auth/login` | Login + terbitkan token |
| POST | `/auth/logout` | Cabut token |
| GET | `/auth/me` | Pengguna saat ini |
| GET | `/documents` | Daftar riwayat |
| POST | `/documents` | **Upload + mulai analisis** |
| GET | `/documents/{document}` | Detail dokumen |
| GET | `/documents/{document}/status` | Polling progres analisis |
| POST | `/documents/{document}/retry` | Ulangi analisis yang gagal |
| DELETE | `/documents/{document}` | Hapus satu dokumen |
| DELETE | `/documents` | Bersihkan riwayat |
| GET | `/documents/{document}/references` | Daftar referensi |
| GET | `/references/{reference}` | Detail referensi + kandidat |
| PATCH | `/references/{reference}/finding` | Override review manual |
| GET | `/documents/{document}/citations` | Daftar sitasi |
| GET | `/citations/{citation}` | Detail sitasi + lokasi |
| PATCH | `/citations/{citation}` | Pasangkan/lepas referensi |
| GET | `/documents/{document}/findings` | Feed temuan / sorotan |
| POST | `/documents/{document}/reports` | Buat laporan |
| GET | `/documents/{document}/reports` | Daftar laporan |
| GET | `/reports/{report}` | Detail laporan |
| DELETE | `/reports/{report}` | Hapus laporan |

