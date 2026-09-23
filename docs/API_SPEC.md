# API Specification — Dafpus Cek

This is the canonical contract between the Vue.js frontend and the Laravel backend. The FastAPI
service is an **internal inference server only** and is not part of the public contract.

---

## 1. Architecture

```text
Vue.js (SPA)
   │  REST / JSON  (public contract, this document)
   ▼
Laravel API  ── auth, documents, references, citations, findings, reports, DB
   │
   ├── MySQL/Postgres  (see docs/DB_SCHEMA.md)
   ├── Crossref REST API (outbound HTTP, orchestrated by Laravel)
   ├── Queue worker  (async analysis pipeline)
   └── FastAPI inference server (internal, private network)
          ├── GROBID  → extract references + in-text citations + coordinates
          └── SBERT   → sentence embeddings
```

**Responsibility split**

| Concern | Owner |
|---|---|
| Auth, persistence, analysis orchestration, status/progress | Laravel |
| Crossref lookups, candidate ranking, scoring, citation resolution | Laravel |
| String similarity (Jaro-Winkler, Levenshtein), exact matching | Laravel |
| Document parsing / reference + citation extraction (GROBID) | FastAPI (inference) |
| Semantic embeddings (SBERT) | FastAPI (inference) |

Laravel is the source of truth. GROBID/SBERT algorithms are **never** exposed as public endpoints.

---

## 2. Conventions

### 2.1 Base URL & versioning

```text
Production : https://api.dafpus.example/api/v1
Local      : http://localhost:8000/api/v1
```

All endpoints below are relative to the base URL and require the `Accept: application/json` header.

### 2.2 Authentication

Bearer token via **Laravel Sanctum** personal access tokens (approved dependency).

```http
Authorization: Bearer <token>
```

Tokens are issued on register/login and revoked on logout. All endpoints except
`POST /auth/register` and `POST /auth/login` require authentication.

### 2.3 Data types & formats

| Type | Format |
|---|---|
| ID | UUID v4 string |
| Timestamp | ISO 8601 UTC, e.g. `2026-09-23T15:30:00Z` |
| Confidence | decimal `0.0000`–`1.0000` (frontend renders as %) |
| Enum values | lower_snake_case |

### 2.4 Success envelope

Single resource:

```json
{
  "data": { "id": "…" },
  "message": "Optional human-readable note"
}
```

Collection:

```json
{
  "data": [ { "id": "…" } ],
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

`message` is only present on actions (create/update/delete/trigger). `meta` is only present on
paginated collections.

### 2.5 Error envelope

Every non-2xx response uses the same shape:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": { "file": ["The file must be a PDF."] }
  }
}
```

`details` is optional (present for validation and domain-specific failures).

| HTTP | `code` | When |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed request |
| 401 | `UNAUTHENTICATED` | Missing/expired/invalid token |
| 403 | `FORBIDDEN` | Action not permitted |
| 404 | `NOT_FOUND` | Unknown id or not owned by caller |
| 409 | `CONFLICT` | Invalid state transition (e.g. analysis already running) |
| 413 | `PAYLOAD_TOO_LARGE` | File exceeds size limit |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | File is not a PDF |
| 422 | `VALIDATION_ERROR` | Field validation failed |
| 429 | `RATE_LIMITED` | Rate limit exceeded |
| 500 | `SERVER_ERROR` | Unhandled error |
| 503 | `INFERENCE_UNAVAILABLE` | GROBID/SBERT unreachable or analysis failed |

### 2.6 Enums

**Document status** (`researched_documents.status`) — approved set

| Value | Meaning |
|---|---|
| `pending` | Persisted, analysis not started yet (schema default) |
| `processing` | Pipeline running |
| `completed` | Pipeline finished (even if findings exist) |
| `failed` | Pipeline aborted; see `analysis_error` |

**Analysis step** (`researched_documents.analysis_step`)

`queued` · `extracting` · `persisting` · `crossref_validation` · `embedding` · `scoring` ·
`resolving_citations` · `generating_report` · `completed`

**Reference finding status** (`reference_findings.status`)

| Value | Meaning |
|---|---|
| `pending` | Not yet evaluated |
| `valid` | Matched confidently |
| `suspicious` | Matched but low confidence / partial mismatch |
| `invalid` | Match found but metadata conflicts |
| `not_found` | No candidate found in Crossref |

**Citation status** (derived, not stored — recomputed from the pairing and the reference finding)

| Value | Condition |
|---|---|
| `valid` | Paired, and the reference finding status is `valid` or `suspicious` |
| `unreliable` | Paired, but the reference finding status is `invalid` or `not_found` |
| `pending` | Paired, but the reference finding is still `pending` |
| `hallucination` | No reference pair (`researched_document_reference_id` is `null`) |

Because the status is derived, changing a reference finding (e.g. via manual review) immediately
changes the status of every citation pointing to it.

**Report status** (`generated_document_reports.status`)

`pending` · `processing` · `completed` · `failed`

**Finding severity** (derived)

`high` · `medium` · `low` · `info`

Severity mapping:

| Finding | Severity |
|---|---|
| reference `not_found` | high |
| reference `invalid` | high |
| citation `hallucination` | high |
| citation `unreliable` | severity of its paired reference finding |
| reference `suspicious` | medium |
| reference `pending` | info |

### 2.7 Pagination, filtering, sorting

Collections accept `page` and `per_page` (default 15, max 100). List filters are documented per
endpoint. Invalid filters return `422`. Default sort is `created_at` descending unless stated.

### 2.8 Ownership & deletion

A resource is accessible only to the owner of its parent `researched_document`. Accessing another
user's resource returns `404 NOT_FOUND` (do not leak existence).

Deletion is a **hard delete** with cascade to dependent rows (references, citations, locations,
findings, candidates, files, reports).

### 2.9 Rate limits (defaults)

| Scope | Limit |
|---|---|
| `POST /auth/login`, `POST /auth/register` | 5 / minute / IP |
| `POST /documents` | 10 / minute / user |
| `GET /documents/{document}/status` | 120 / minute / user |
| Authenticated endpoints (general) | 60 / minute / user |

Exceeding a limit returns `429`:

```json
{ "error": { "code": "RATE_LIMITED", "message": "Too many attempts. Please try again later." } }
```

---

## 3. Auth — `/auth`

### POST `/auth/register`

**Request**

```json
{
  "name": "Daniel Belawa Koten",
  "email": "daniel@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Rules: `name` required string ≤ 255; `email` required, unique, valid; `password` required,
confirmed, min 8.

**Success — `201`**

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

**Failure — `422`**

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

**Request**

```json
{ "email": "daniel@example.com", "password": "password123" }
```

**Success — `200`**

```json
{
  "data": {
    "user": { "id": "9f1c…", "name": "Daniel Belawa Koten", "email": "daniel@example.com" },
    "token": "2|eyJhbGciOi…",
    "token_type": "Bearer"
  }
}
```

**Failure — `422`** (invalid credentials)

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Kredensial tidak valid.",
    "details": { "email": ["These credentials do not match our records."] }
  }
}
```

**Failure — `429`** (throttled) — see §2.9.

### POST `/auth/logout`

Revokes the current token.

**Success — `200`**

```json
{ "data": null, "message": "Logout berhasil." }
```

**Failure — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

### GET `/auth/me`

**Success — `200`**

```json
{ "data": { "id": "9f1c…", "name": "Daniel Belawa Koten", "email": "daniel@example.com", "created_at": "2026-09-23T15:30:00Z" } }
```

**Failure — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

---

## 4. Documents — `/documents`

Upload **and** analysis start are merged: `POST /documents` persists the document, attaches the
file, and dispatches the analysis pipeline automatically.

### POST `/documents`

`Content-Type: multipart/form-data`

| Field | Type | Required | Notes |
|---|---|---|---|
| `file` | file | yes | **PDF only** (DOCX unsupported in v1), ≤ 20 MB |
| `name` | string | no | Defaults to the original filename |

**Behavior**

1. Store the uploaded file and create `researched_documents` (status `pending`) + `files` record.
2. Dispatch `AnalyzeDocumentJob` to the queue.
3. Respond immediately; analysis runs asynchronously (poll `GET /documents/{id}/status`).

**Success — `202 Accepted`**

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

**Failure — `422` / `413` / `415` / `503`**

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

History list. Filters: `status`, `q` (name search), `sort` (`created_at|-created_at`, default
`-created_at`).

**Success — `200`**

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

**Failure — `401`**

```json
{ "error": { "code": "UNAUTHENTICATED", "message": "Unauthenticated." } }
```

### GET `/documents/{document}`

Full document detail (same shape as above; `summary` is present once `status = completed`).

**Success — `200`** · **Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/documents/{document}/status`

Lightweight polling endpoint. Recommended client interval: **every 2 s**, backing off to **5 s**
after 30 s; stop on `completed` or `failed`.

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### POST `/documents/{document}/retry`

Re-runs the pipeline for a `failed` document. Allowed only when `status = failed`.

**Success — `202`**

```json
{ "data": { "id": "b2d4…", "status": "pending", "progress": 0, "current_step": "queued" }, "message": "Analisis dijadwalkan ulang." }
```

**Failure — `409`**

```json
{
  "error": {
    "code": "CONFLICT",
    "message": "Analisis hanya dapat diulang untuk dokumen yang gagal."
  }
}
```

### DELETE `/documents/{document}`

Hard-deletes one document and cascades to its references, citations, locations, findings,
candidates, files, and reports.

**Success — `204`** (no body) · **Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### DELETE `/documents`

Hard-deletes the authenticated user's entire history.

**Success — `204`** (no body)

---

## 5. References — `/references`

A *reference* is a bibliography entry (`researched_document_references`). Its review verdict lives
in `reference_findings`; candidates live in `reference_finding_candidates`.

### GET `/documents/{document}/references`

Filters: `status` (finding status), `has_doi` (bool). Paginated.

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/references/{reference}`

Includes the finding, the ranked candidates, highlight locations, and the resolved citations
pointing to this reference.

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Referensi tidak ditemukan." } }
```

### PATCH `/references/{reference}/finding`

Manual review override. Lets a user change the verdict and/or pick another candidate. Sets
`is_manual = true` and stamps `reviewed_by` / `reviewed_at`.

**Request**

```json
{
  "status": "valid",
  "selected_candidate_id": "c-102-uuid",
  "reason": "Diverifikasi manual oleh pengguna."
}
```

Rules: `status` required, one of `valid|suspicious|invalid|not_found`; `selected_candidate_id`
nullable, must belong to this finding; `reason` nullable string.

**Success — `200`**

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

**Failure — `422` / `404`**

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

## 6. Citations — `/citations`

A *citation* is one in-text occurrence (`researched_document_citations`). One reference can have
many citation rows. Citation status is **derived** (§2.6): unpaired citations are marked **`hallucination`**,
and citations paired with an `invalid`/`not_found` reference are marked **`unreliable`** (a
`suspicious` reference still yields a `valid` citation). Each citation may have multiple highlight
locations (`researched_document_citation_locations`).

### GET `/documents/{document}/citations`

Filters: `status` (`valid|unreliable|pending|hallucination`), `reference_id`. Paginated.

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

### GET `/citations/{citation}`

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Sitasi tidak ditemukan." } }
```

### PATCH `/citations/{citation}`

Manually pair a `hallucination` citation with a reference, or clear the pairing.

**Request**

```json
{ "researched_document_reference_id": "r-102-uuid" }
```

Rules: field nullable; when present the reference must belong to the same document.

**Success — `200`**

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

**Failure — `422` / `404`**

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

## 7. Findings / Highlights — `/findings`

A derived, read-only feed combining problem reference findings, `unreliable` citations, and `hallucination`
citations. Used by the document viewer to render the issue list and highlights.

### GET `/documents/{document}/findings`

Filters: `type` (`reference_invalid|reference_suspicious|reference_not_found|citation_unreliable|citation_hallucination`),
`severity` (`high|medium|low|info`). Paginated.

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Dokumen tidak ditemukan." } }
```

---

## 8. Reports — `/reports`

Backed by `generated_document_reports`. One report per document, generated asynchronously as a
**PDF only** (no DOCX/Excel export in v1). See `docs/DB_SCHEMA.md` for the full schema.

### POST `/documents/{document}/reports`

**Behavior**: creates a report row (`status = pending`), dispatches a generation job, responds
immediately. Allowed only when the document `status = completed`.

**Success — `202`**

```json
{
  "data": { "id": "rep-uuid", "document_id": "b2d4…", "status": "pending", "generated_at": null },
  "message": "Laporan sedang dibuat."
}
```

**Failure — `409`**

```json
{
  "error": {
    "code": "CONFLICT",
    "message": "Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis."
  }
}
```

### GET `/documents/{document}/reports`

**Success — `200`**

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

**Success — `200`**

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

**Failure — `404`**

```json
{ "error": { "code": "NOT_FOUND", "message": "Laporan tidak ditemukan." } }
```

### DELETE `/reports/{report}`

Hard-deletes the report and its stored file.

**Success — `204`** (no body) · **Failure — `404`**

---

## 9. Internal Inference API (FastAPI — not public)

Called only by Laravel's queue workers. Runs on a **private network only**; no authentication in v1.

Base URL: `http://inference:8000` (internal).

### GET `/health`

**Success — `200`**: `{ "status": "ok", "grobid": "up", "sbert": "up" }`

### POST `/v1/extract` — GROBID

`multipart/form-data` with `file` (**PDF only**). Returns extracted header, references, in-text
citations and coordinates.

**Success — `200`**

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

**Failure — `422`** (unparseable PDF) · **`503`** (GROBID down)

```json
{ "error": { "code": "EXTRACTION_FAILED", "message": "GROBID could not process the document." } }
```

### POST `/v1/embeddings` — SBERT

**Request**

```json
{ "texts": ["Deep learning", "Sistem Deteksi Plagiarisme"], "model": "sbert" }
```

**Success — `200`**

```json
{ "data": { "model": "sbert", "dimensions": 768, "embeddings": [[0.021, -0.133, "…"], [0.004, 0.088, "…"]] } }
```

**Failure — `422`** (empty `texts`) · **`503`** (model unavailable)

```json
{ "error": { "code": "INFERENCE_FAILED", "message": "SBERT model is unavailable." } }
```

> Batch `/v1/embeddings` requests (e.g. 32 texts) to bound latency and memory.

---

## 10. Analysis pipeline (orchestrated by Laravel)

Triggered by `POST /documents`.

```text
1. Store PDF file (files) → create researched_documents (status=pending, analysis_step=queued)
2. FastAPI POST /v1/extract (GROBID)
      → insert researched_document_references (+ text offsets)
      → insert researched_document_reference_locations
      → insert researched_document_citations
      → insert researched_document_citation_locations
3. For each reference:
      → Crossref lookup (DOI or bibliographic search)
      → insert reference_finding_candidates (ranked)
4. Similarity scoring (Laravel): Jaro-Winkler + Levenshtein + SBERT embeddings
      → compute confidence, pick selected_candidate_id
      → upsert reference_findings (status, reason)
5. Resolve citations → set researched_document_citations.researched_document_reference_id
      → citations left unpaired are surfaced as status = hallucination
6. Compute summary counts → researched_documents.status=completed
   On any unrecoverable error → status=failed + analysis_error
```

Progress is reported via `analysis_progress` / `analysis_step` / `analysis_error` on
`researched_documents`.

---

## 11. Schema mapping

| API concept | Table / column |
|---|---|
| Document | `researched_documents` (`id`, `user_id`, `name`, `status`) |
| Document file | `files` (polymorphic via `fileable_type`, `fileable_id`) |
| Document status/progress | `researched_documents.status` + `analysis_progress`, `analysis_step`, `analysis_error`, `analysis_started_at`, `analysis_completed_at` |
| Reference | `researched_document_references` (`raw_text`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, `text_start_offset`, `text_end_offset`) |
| Reference highlight | `researched_document_reference_locations` |
| Reference verdict | `reference_findings` (`status`, `confidence`, `reason`, `selected_candidate_id`, `is_manual`, `reviewed_by`, `reviewed_at`) |
| Reference candidates | `reference_finding_candidates` (`rank`, `confidence`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, `url`, `match_reason`) |
| Citation | `researched_document_citations` (`citation_text`, `citation_marker`, `context_*`, `text_*_offset`, `occurrence_index`, `researched_document_reference_id`) |
| Citation status | **derived**: `hallucination` if unpaired; otherwise `valid` / `unreliable` / `pending` from the paired reference finding status |
| Citation highlight | `researched_document_citation_locations` (`page_number`, `x`, `y`, `width`, `height`, `page_*`, `coordinate_system`, `location_index`) |
| Findings feed | derived from `reference_findings` + `hallucination` citations |
| Report | `generated_document_reports` (+ `status`, nullable `file_id`, nullable `generated_at`) |

---

## 12. Endpoint summary

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/register` | Register + issue token |
| POST | `/auth/login` | Login + issue token |
| POST | `/auth/logout` | Revoke token |
| GET | `/auth/me` | Current user |
| GET | `/documents` | History list |
| POST | `/documents` | **Upload + start analysis** |
| GET | `/documents/{document}` | Document detail |
| GET | `/documents/{document}/status` | Poll analysis progress |
| POST | `/documents/{document}/retry` | Retry failed analysis |
| DELETE | `/documents/{document}` | Delete one document |
| DELETE | `/documents` | Purge history |
| GET | `/documents/{document}/references` | List references |
| GET | `/references/{reference}` | Reference detail + candidates |
| PATCH | `/references/{reference}/finding` | Manual review override |
| GET | `/documents/{document}/citations` | List citations |
| GET | `/citations/{citation}` | Citation detail + locations |
| PATCH | `/citations/{citation}` | Pair/unpair reference |
| GET | `/documents/{document}/findings` | Findings / highlight feed |
| POST | `/documents/{document}/reports` | Generate report |
| GET | `/documents/{document}/reports` | List reports |
| GET | `/reports/{report}` | Report detail |
| DELETE | `/reports/{report}` | Delete report |

