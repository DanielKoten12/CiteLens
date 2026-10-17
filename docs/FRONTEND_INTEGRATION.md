# Integrasi Frontend ke API — Catatan Pekerjaan

Dokumen ini merangkum penyambungan frontend (Vue) ke API Laravel: apa yang diubah, bagaimana
diuji, apa yang belum dikerjakan beserta alasannya, dan pertanyaan yang butuh keputusan tim.

Acuan kontrak: [`API_SPEC_ID.md`](./API_SPEC.md).

---

## 1. Ringkasan

Sebelumnya frontend memakai data mock (`setTimeout`, array statis). Sekarang halaman berikut
memakai API asli:

| Halaman | Status |
|---|---|
| Login, Register, Logout, pemulihan sesi | Tersambung |
| Upload PDF | Tersambung |
| Halaman progres (polling status, retry) | Tersambung |
| Riwayat | Tersambung |
| Dokumen Saya | Tersambung |
| Hasil (ResultView) | **Belum** (masih mock) |
| Profil, Pengaturan, Lupa Kata Sandi | **Belum** (API belum ada) |

---

## 2. Perubahan

Semua perubahan ada di `frontend/src/`.

### File baru

| File | Fungsi |
|---|---|
| `lib/api.ts` | Klien HTTP: header token Bearer, envelope error `{ error: { code, message, details } }`, `ApiError`, handler 401 global |
| `lib/documents.ts` | Seluruh endpoint dokumen, referensi, sitasi, temuan, dan laporan dalam satu objek `documentsApi` |
| `types/api.ts` | Tipe TypeScript sesuai `API_SPEC.md` (UUID, enum status, bentuk respons) |
| `composables/useDocumentPolling.ts` | Polling `GET /documents/{id}/status`: 2 dtk, melambat ke 5 dtk setelah 30 dtk, berhenti saat `completed` / `failed` atau 404 |

### File yang diubah

| File | Perubahan |
|---|---|
| `stores/auth.ts` | Mock diganti panggilan `/auth/login`, `/register`, `/logout`, `/me`; token disimpan di `localStorage`; `init()` memulihkan sesi |
| `main.ts` | `auth.init()` dijalankan sebelum router dipasang; handler 401 membersihkan sesi lalu ke login |
| `router/index.ts` | Route `processing` dan `result` menerima `:id?` (opsional selama ResultView masih mock) dengan `props: true` |
| `types/index.ts` | `AuthUser` ditambah `id?` (isi lain tidak berubah) |
| `views/LoginView.vue` | Memanggil API, menangani 422 / 429 / error jaringan |
| `views/RegisterView.vue` | Memanggil API, memetakan error per field dari server, mengirim `password_confirmation` |
| `views/UploadView.vue` | `POST /documents`, validasi sesuai spec (PDF, maks 20 MB), pesan error 413/415/422/429/503 |
| `views/ProcessingView.vue` | Progres asli dari polling (bukan timer palsu), tampilan gagal + tombol "Coba lagi" (`/retry`) |
| `views/HistoryView.vue` | Daftar dokumen asli: cari, urut, filter status, paginasi, hapus satu / semua, unduh laporan |
| `views/DocumentsView.vue` | Kartu dokumen asli: cari, paginasi, hapus |

---

## 3. Perbedaan dari prototipe (untuk ditinjau)

Beberapa elemen prototipe tidak punya padanan di API, sehingga tampilannya disesuaikan:

| Prototipe | Sekarang | Alasan |
|---|---|---|
| `trustScore` / "Kepercayaan" | "Terverifikasi" = referensi `valid` ÷ total referensi | API tidak punya skor kepercayaan. Ini rasio tampilan, **bukan** verdict dari backend |
| Filter skor (≥90%, 70–89%, <70%) | Filter status (Selesai / Diproses / Gagal) | API hanya mendukung filter `status` |
| Jumlah halaman (`pages`) | Dihapus | Tidak dikirim API |
| Kolom "Halu" | "Tidak valid" (`invalid` + `not_found`) dan "Sitasi halu" (`hallucination`) | Sesuai definisi di spec: status referensi dan status sitasi adalah dua hal berbeda |
| "Dokumen tidak disimpan di server kami" | "Dokumenmu hanya bisa diakses oleh akunmu dan bisa dihapus kapan saja" | Backend memang menyimpan file; kalimat lama tidak akurat |
| Sumber: Crossref, OpenAlex, Semantic Scholar | Hanya Crossref | Spec hanya memakai Crossref |
| Langkah progres: ekstrak teks, deteksi sitasi, validasi Crossref & OpenAlex, susun laporan | Ekstrak referensi & sitasi, validasi Crossref, skor kecocokan, tautkan sitasi | Dipetakan dari `current_step` pipeline asli |

---

## 4. Menjalankan secara lokal (tanpa Docker)

Lingkungan pengujian: backend dan frontend dijalankan manual, **tanpa** GROBID / inference dan
Gotenberg.

```powershell
# terminal 1 — backend
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
composer run dev

# terminal 2 — frontend
cd frontend
npm install
npm run dev
```

Catatan:

- `composer.lock` mengunci paket (Symfony 8, Pest 5, PHPUnit 13) yang membutuhkan **PHP ≥ 8.4.1**.
  Dengan PHP 8.3, `composer install` gagal. Sebaiknya persyaratan versi PHP di dokumentasi setup
  disesuaikan.
- Batas upload PHP (`upload_max_filesize`, `post_max_size`) di `php.ini` belum diubah dari
  pengaturan lokal awal. Upload 6,1 MB berhasil; untuk PDF mendekati batas 20 MB, nilai
  tersebut perlu minimal `20M` dan `25M`.
- Pemeriksaan tipe: `npm run type-check` (`vue-tsc --noEmit`) bisa lolos tanpa memeriksa `src` pada
  konfigurasi tsconfig bergaya "solution". Gunakan
  `npx vue-tsc --noEmit -p tsconfig.app.json` untuk pemeriksaan yang sebenarnya.

---

## 5. Pengujian manual

Diuji secara manual di lingkungan lokal di atas.

| Skenario | Hasil |
|---|---|
| Register akun baru | Berhasil, diarahkan ke halaman upload |
| Refresh halaman setelah login | Sesi tetap, tidak kembali ke login |
| Register dengan email yang sudah ada | Pesan "Email sudah terdaftar." |
| Login dengan sandi salah | Pesan "Email atau kata sandi salah." |
| Login dengan sandi benar, logout | Berhasil |
| Akses halaman terlindungi tanpa login | Diarahkan ke login |
| Upload file selain PDF | Ditolak di klien dengan pesan format |
| Upload PDF 6,1 MB | Diterima backend; progres sampai 5% lalu gagal di langkah "Mengekstrak referensi & sitasi" dengan pesan "Layanan analisis tidak tersedia" (GROBID tidak tersedia secara lokal) — perilaku yang diharapkan |
| Tombol "Coba lagi" / "Unggah file lain" pada tampilan gagal | Berfungsi |
| Riwayat: tampil dengan badge Gagal, cari, urutan, filter status, hapus satu, hapus semua | Berfungsi |
| Dokumen Saya: tampil sebagai kartu (badge Gagal + pesan error), cari, Lihat Progres, hapus | Berfungsi |

---

## 6. Belum dikerjakan / belum teruji, beserta alasannya

| Item | Status | Alasan |
|---|---|---|
| **ResultView** | Belum disambungkan | Membutuhkan dokumen berstatus `completed` beserta referensi, sitasi, dan temuan. Analisis tidak bisa selesai tanpa GROBID. Rencana: data contoh lewat seeder, atau data dari stack Docker lengkap (saya keterbatasan ruang SSD hehe) |
| **Jalur sukses di halaman progres** (selesai lalu pindah ke hasil) | Belum teruji | Belum ada dokumen yang berhasil dianalisis di lingkungan lokal |
| **Unduh laporan PDF** (Riwayat) | Kode ada, belum teruji | Membutuhkan dokumen `completed` dan Gotenberg (hanya ada di stack Docker) |
| **Paginasi** Riwayat dan Dokumen Saya | Kode ada, belum teruji | Baru terlihat dengan lebih dari 15 (Riwayat) / 12 (Dokumen Saya) dokumen |
| **Variasi ukuran PDF** (mendekati dan melebihi 20 MB) | Belum diuji | Baru satu ukuran yang dicoba (6,1 MB). Penolakan di klien untuk file > 20 MB dan respons 413 dari server belum diverifikasi |
| **Profil, Pengaturan** | Tetap statis | Tidak ada endpoint ubah profil / ubah sandi di `API_SPEC.md` |
| **Lupa Kata Sandi** | Tetap statis | Tidak ada endpoint reset sandi di `API_SPEC.md` |
| **Parameter `:id` wajib** di route `processing` / `result` | Masih opsional (`:id?`) | Supaya view yang belum disambungkan (ResultView) tidak error. Dijadikan wajib setelah semua pemanggil diperbarui |
| `stores/document.ts` | Masih dipanggil dari UploadView | Belum diketahui apakah ResultView lama membacanya; dibiarkan agar tidak merusak |

---

## 7. Pertanyaan untuk tim backend

1. **Reset sandi dan ubah profil:** apakah endpoint-nya direncanakan? Jika ya, kontraknya perlu masuk ke `API_SPEC.md`.
2. **`file.url` pada dokumen:** apakah URL itu bisa dibuka langsung oleh `pdfjs-dist` tanpa header `Authorization`? Ini menentukan cara ResultView menampilkan PDF.
3. **`trustScore` dan jumlah halaman:** apakah akan ditambahkan ke ringkasan dokumen? Saat ini frontend memakai rasio `valid ÷ total` sebagai pengganti tampilan.
4. **`download_url` laporan:** apakah URL bertanda tangan (signed) sehingga bisa dibuka lewat `window.open` tanpa token?
5. **Dukungan DOCX:** spec v1 hanya PDF. Konfirmasi bahwa teks "PDF atau DOCX" di bagian lain UI memang harus dihapus.

---

## 8. Catatan teknis

- **Token** disimpan di `localStorage` (kunci `citelens_token`) dan dikirim sebagai `Authorization: Bearer`. Ini pilihan umum untuk Sanctum bearer token, tetapi rentan terhadap XSS; hindari merender HTML tak tepercaya.
- **401 global:** bila token kedaluwarsa atau dicabut, `lib/api.ts` memanggil handler yang membersihkan sesi lalu mengarahkan ke login.
- **Tanpa top-level `await`** di `main.ts` (dibungkus fungsi `bootstrap`), karena target build bawaan Vite dapat menolaknya.
- **Rate limit** login/register 5 per menit per IP (sesuai spec) ditangani dengan pesan khusus.
