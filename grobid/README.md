# Dafpus Cek — Internal Document Extraction API

Layanan FastAPI internal untuk **mengambil data dari dokumen PDF** menggunakan GROBID. Pada tahap pertama, service hanya mengekstrak metadata dokumen, daftar referensi, sitasi dalam teks, dan lokasi koordinat jika tersedia. Service ini bukan API publik frontend; Laravel memanggilnya melalui jaringan privat.

## Cakupan tahap pertama

- Menerima PDF melalui `POST /v1/extract`.
- Mengekstrak header dasar: judul, penulis, abstrak, tahun publikasi, dan DOI jika tersedia.
- Mengekstrak referensi: teks asli, judul, penulis, nama publikasi, tahun, DOI, offset teks, dan lokasi.
- Mengekstrak sitasi: teks sitasi, marker, konteks sebelum/sesudah, offset teks, urutan kemunculan, indeks referensi dari GROBID, dan lokasi.
- Menyediakan `GET /health` untuk memeriksa konektivitas GROBID.

Belum termasuk SBERT/embeddings, Crossref, pencocokan referensi, penentuan validitas, resolusi sitasi, database, autentikasi, antrean analisis, atau laporan. Bagian-bagian tersebut tetap di luar service ekstraksi ini dan dapat ditambahkan pada tahap berikutnya bila diperlukan.

## Struktur

```text
dafpus-inference-api/
├── app/
│   ├── main.py                 # Endpoint, validasi, dan format error
│   ├── config.py               # Konfigurasi environment
│   ├── schemas.py              # Schema respons dasar
│   └── services/
│       ├── grobid.py           # Komunikasi dengan GROBID
│       └── tei_parser.py       # TEI XML -> JSON ekstraksi
├── requirements.txt
├── Dockerfile
└── .env.example
```

## Menjalankan lokal

Python 3.11 direkomendasikan.

```bash
python -m venv .venv
# Windows:
.venv\\Scripts\\activate
# macOS/Linux:
source .venv/bin/activate

pip install -r requirements.txt
cp .env.example .env
uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
```

Pastikan GROBID aktif dan `GROBID_URL` mengarah ke host yang dapat dijangkau API. Di Docker, gunakan nama service seperti `http://grobid:8070`, bukan `localhost` kecuali GROBID berada dalam container yang sama.

Dokumentasi interaktif: `http://localhost:8000/docs`.

## Endpoint

### `GET /health`

Contoh saat GROBID aktif:

```json
{"status":"ok","grobid":"up"}
```

Jika GROBID tidak bisa dihubungi, `status` menjadi `degraded` dan `grobid` menjadi `down`.

### `POST /v1/extract`

- `Content-Type: multipart/form-data`
- Field file: `file`
- PDF saja; default maksimum 20 MB

```bash
curl -X POST http://localhost:8000/v1/extract -F "file=@artikel.pdf"
```

Bentuk respons ringkas:

```json
{
  "data": {
    "header": {
      "title": "Judul dokumen",
      "authors": ["Nama Penulis"],
      "abstract": null,
      "publication_year": 2024,
      "doi": null
    },
    "references": [],
    "citations": []
  }
}
```

Koordinat bergantung pada informasi yang diberikan GROBID. Offset dihitung terhadap teks TEI yang dinormalisasi; bila teks dari GROBID tidak cocok dengan teks referensi/sitasi, offset terkait akan `null`. Metadata yang tidak ditemukan juga akan bernilai `null` atau daftar kosong.

## Format error

Semua error memakai bentuk yang konsisten:

```json
{
  "error": {
    "code": "EXTRACTION_FAILED",
    "message": "GROBID could not process the document."
  }
}
```

Kode utama: `VALIDATION_ERROR`, `UNSUPPORTED_MEDIA_TYPE`, `PAYLOAD_TOO_LARGE`, dan `EXTRACTION_FAILED`.

## Integrasi dan batasan

- Laravel mengirim file dengan field multipart bernama `file` ke `POST /v1/extract`.
- Laravel tetap menangani penyimpanan, status dokumen, autentikasi, database, antrean, dan alur analisis lanjutan.
- Jangan ekspos endpoint inference ini langsung ke internet; batasi ke jaringan privat.
- Uji dengan beberapa PDF nyata sebelum produksi. Kualitas metadata, koordinat, dan offset tergantung pada keluaran GROBID dan struktur PDF.
