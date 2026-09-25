# CiteLens — Frontend Guide

Dokumen ini menjelaskan tech stack dan cara menjalankan bagian **frontend** proyek CiteLens (nama internal repo: `Dafpus Sitasi Halu`) dari nol sampai bisa `npm run dev`.

> CiteLens adalah aplikasi untuk mengecek validitas sitasi/daftar pustaka pada dokumen. Frontend (Vue.js SPA) berkomunikasi dengan backend Laravel lewat REST API; backend itu sendiri berbicara ke server inferensi FastAPI (GROBID + SBERT) secara internal — frontend **tidak pernah** memanggil FastAPI langsung.

```text
Vue.js (SPA, kamu di sini)
   │  REST / JSON
   ▼
Laravel API  ──  MySQL/Postgres, Crossref API, queue worker
   │
   ▼
FastAPI (internal, GROBID + SBERT)
```

---

## 1. Tech Stack

| Kategori | Tools |
|---|---|
| Framework | [Vue 3](https://vuejs.org/) (`<script setup>` + Composition API) |
| Build tool | [Vite 6](https://vite.dev/) |
| Bahasa | TypeScript |
| Routing | [Vue Router 4](https://router.vuejs.org/) |
| State management | [Pinia](https://pinia.vuejs.org/) |
| Styling | [Tailwind CSS v4](https://tailwindcss.com/) (via plugin `@tailwindcss/vite`, bukan config file terpisah) |
| Komponen UI | [shadcn-vue](https://www.shadcn-vue.com/) (style `new-york`) dibangun di atas [reka-ui](https://reka-ui.com/) |
| Utility styling | `class-variance-authority`, `clsx`, `tailwind-merge` |
| Icon | `lucide-vue-next` |
| Composable helpers | `@vueuse/core` |
| Package manager | npm |

### Struktur folder penting

```text
frontend/
├── src/
│   ├── assets/          # main.css (entry Tailwind), logo, dll
│   ├── components/
│   │   ├── common/      # AppLogo, PasswordInput, UserProfileMenu
│   │   ├── layout/      # AppHeader
│   │   └── ui/          # komponen shadcn-vue (Button, Card, Input, Alert, Label, ...)
│   ├── lib/utils.ts      # helper cn() dll
│   ├── router/index.ts   # definisi route + auth guard
│   ├── stores/auth.ts    # Pinia store auth (masih MOCK, lihat catatan di bawah)
│   ├── types/index.ts    # tipe TypeScript bersama
│   ├── views/            # LoginView, RegisterView, UploadView, ProcessingView, ResultView, HistoryView
│   ├── App.vue
│   └── main.ts            # entry point app
├── components.json        # config shadcn-vue (path alias, style, base color)
├── vite.config.ts
├── tsconfig*.json
└── package.json
```

**Alias import:** `@/` mengarah ke `src/` (dikonfigurasi di `vite.config.ts` dan `tsconfig.app.json`).

**Routing:** ada guard di `router/index.ts` yang redirect ke `/login` kalau belum `isAuthenticated`, dan redirect balik ke `/` kalau sudah login tapi buka halaman publik (`/login`, `/register`).

> ⚠️ **Catatan penting:** `src/stores/auth.ts` saat ini pakai **mock login/register** (`setTimeout` yang selalu resolve, tidak memanggil API sungguhan). Kalau backend Laravel sudah siap, bagian ini perlu diganti dengan pemanggilan API asli (lihat `docs/API_SPEC.md` / `docs/API_SPEC_ID.md` di root repo untuk kontrak endpoint-nya).

---

## 2. Prasyarat

Pastikan sudah terinstall di komputer kamu:

- **Node.js** ≥ 20 (proyek ini dikembangkan dengan Node v22 — disarankan pakai versi LTS terbaru)
- **npm** (sudah bawaan Node.js)

Cek dengan:

```bash
node -v
npm -v
```

---

## 3. Cara Menjalankan dari Awal

### Langkah 1 — Clone repo (jika belum)

```bash
git clone <url-repo-CiteLens>
cd CiteLens
```

### Langkah 2 — Masuk ke folder frontend

```bash
cd frontend
```

### Langkah 3 — Install dependencies

```bash
npm install
```

### Langkah 4 — Jalankan development server

```bash
npm run dev
```

Secara default server berjalan di `http://localhost:5173` (dikonfigurasi `host: 0.0.0.0`, `port: 5173` di `vite.config.ts`, jadi bisa juga diakses dari device lain di jaringan yang sama lewat IP komputer kamu).

Buka browser ke `http://localhost:5173` — kamu akan diarahkan ke halaman `/login` karena belum ada sesi login (ingat, saat ini masih mock, jadi login dengan email/password apa saja akan berhasil setelah ~1.2 detik).

---

## 4. Script npm yang tersedia

| Perintah | Fungsi |
|---|---|
| `npm run dev` | Menjalankan dev server dengan hot reload |
| `npm run build` | Type-check (`vue-tsc -b`) lalu build production ke folder `dist/` |
| `npm run preview` | Preview hasil build production secara lokal |
| `npm run type-check` | Cek tipe TypeScript saja tanpa build |

---

## 5. Menambah Komponen UI (shadcn-vue)

Proyek ini pakai shadcn-vue, jadi komponen UI baru biasanya ditambahkan lewat CLI mereka, bukan `npm install` biasa. Contoh menambah komponen `Dialog`:

```bash
npx shadcn-vue@latest add dialog
```

Komponen baru akan otomatis masuk ke `src/components/ui/` sesuai alias di `components.json`.

---

## 6. Catatan untuk Tim

- Halaman `/login` dan `/register` punya `meta.hideHeader: true` (full-bleed, tanpa `AppHeader`) dan `meta.public: true`.
- Route lain (`/`, `/processing`, `/hasil`, `/riwayat`) butuh login (auth guard otomatis redirect).
- Belum ada file `.env` / variabel `VITE_*` yang dipakai saat ini — base URL API backend belum di-wiring. Kalau mau connect ke Laravel API sungguhan, kemungkinan perlu tambah `VITE_API_BASE_URL` di `.env` dan dipakai di store/service API.
- Untuk memahami kontrak endpoint backend, baca `docs/API_SPEC.md` (Inggris) atau `docs/API_SPEC_ID.md` (Indonesia) di root repo.