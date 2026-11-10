<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { CircleAlert, FileText, LoaderCircle, Upload } from "lucide-vue-next";
import heroLight from "@/assets/animate.gif";
import heroDark from "@/assets/animate2.gif";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import { useDocumentStore } from "@/stores/document";

const router = useRouter();
const documentStore = useDocumentStore();

// Sesuai API_SPEC: hanya PDF, maksimum 20 MB (DOCX belum didukung di v1).
const ACCEPTED_EXTENSIONS = [".pdf"];
const MAX_SIZE_MB = 20;

const selectedFile = ref<File | null>(null);
const isDragging = ref(false);
const uploading = ref(false);
const errorMessage = ref("");
const fileInput = ref<HTMLInputElement | null>(null);

// Ikuti class "dark" di <html> secara reaktif, termasuk saat tema diganti di Pengaturan.
const isDark = ref(document.documentElement.classList.contains("dark"));
let themeObserver: MutationObserver | undefined;

onMounted(() => {
  isDark.value = document.documentElement.classList.contains("dark");
  themeObserver = new MutationObserver(() => {
    isDark.value = document.documentElement.classList.contains("dark");
  });
  themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
});

onBeforeUnmount(() => themeObserver?.disconnect());

const heroImage = computed(() => (isDark.value ? heroDark : heroLight));

// Counter supaya highlight tidak berkedip saat drag melewati elemen anak.
let dragDepth = 0;

const fileSizeLabel = computed(() => {
  if (!selectedFile.value) return "";
  const mb = selectedFile.value.size / (1024 * 1024);
  return mb < 0.1 ? `${Math.max(1, Math.round(selectedFile.value.size / 1024))} KB` : `${mb.toFixed(1)} MB`;
});

const ctaLabel = computed(() => {
  if (uploading.value) return "Mengunggah...";
  return selectedFile.value ? "Mulai Periksa Sitasi" : "Pilih file untuk melanjutkan";
});

function hasValidExtension(file: File) {
  const name = file.name.toLowerCase();
  return ACCEPTED_EXTENSIONS.some((ext) => name.endsWith(ext));
}

function validateAndSet(file: File) {
  errorMessage.value = "";

  if (!hasValidExtension(file)) {
    errorMessage.value = "Format file tidak didukung. Unggah file PDF.";
    return;
  }

  if (file.size > MAX_SIZE_MB * 1024 * 1024) {
    errorMessage.value = `Ukuran file melebihi ${MAX_SIZE_MB} MB.`;
    return;
  }

  selectedFile.value = file;
}

function onInputChange(event: Event) {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0];
  if (file) validateAndSet(file);
  target.value = "";
}

function onDragEnter() {
  if (uploading.value) return;
  dragDepth++;
  isDragging.value = true;
}

function onDragLeave() {
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) isDragging.value = false;
}

function onDrop(event: DragEvent) {
  dragDepth = 0;
  isDragging.value = false;
  if (uploading.value) return;
  const file = event.dataTransfer?.files?.[0];
  if (file) validateAndSet(file);
}

function openFilePicker() {
  if (uploading.value) return;
  fileInput.value?.click();
}

function onZoneClick() {
  if (!selectedFile.value) openFilePicker();
}

function removeFile() {
  if (uploading.value) return;
  selectedFile.value = null;
  errorMessage.value = "";
}

function uploadErrorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    switch (e.status) {
      case 413:
        return `Ukuran file melebihi batas ${MAX_SIZE_MB} MB.`;
      case 415:
        return "Format file tidak didukung. Hanya PDF yang diterima.";
      case 422:
        return e.detailMessages;
      case 429:
        return "Terlalu banyak unggahan. Coba lagi dalam satu menit.";
      case 503:
        return "Layanan analisis sedang tidak tersedia. Coba lagi nanti.";
      default:
        return e.message;
    }
  }
  return "Terjadi kesalahan tak terduga. Coba lagi.";
}

async function handleCta() {
  if (uploading.value) return;
  if (!selectedFile.value) {
    openFilePicker();
    return;
  }

  errorMessage.value = "";
  uploading.value = true;
  try {
    // Disimpan juga ke store lama supaya view yang masih membacanya tidak rusak.
    documentStore.setFile(selectedFile.value);

    const { data } = await documentsApi.upload(selectedFile.value);
    router.push({ name: "processing", params: { id: data.id } });
  } catch (e) {
    errorMessage.value = uploadErrorMessage(e);
  } finally {
    uploading.value = false;
  }
}

const howItWorks = [
  {
    icon: "upload",
    highlighted: true,
    title: "Unggah Dokumen",
    desc: "PDF tugas akhir kamu",
  },
  {
    icon: "loading",
    highlighted: false,
    title: "Ekstrak Sitasi",
    desc: "Semua sitasi in-text terdeteksi otomatis",
  },
  {
    icon: "check",
    highlighted: false,
    title: "Validasi DOI",
    desc: "Dicocokkan dengan database Crossref",
  },
  {
    icon: "report",
    highlighted: false,
    title: "Laporan Hasil",
    desc: "Export PDF dengan anotasi lengkap",
  },
];

const dataSources = [{ name: "Crossref", desc: "100M+ publikasi" }];
</script>

<template>
  <main class="mx-auto w-full max-w-6xl px-4 sm:px-6 py-8 sm:py-12 lg:py-16">
    <!-- ================= Hero ================= -->
    <div class="grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
      <section class="min-w-0 order-2 lg:order-1">
        <span class="hero-badge">
          <span class="hero-badge-dot" aria-hidden="true" />
          Validasi daftar pustaka otomatis
        </span>

        <h1 class="hero-title mt-5">
          Periksa Daftar Pustaka
          <span class="block"><span class="hero-accent">Tugas Akhir</span> Kamu</span>
        </h1>

        <p class="mt-4 max-w-md text-sm sm:text-base leading-relaxed" :style="{ color: 'var(--muted-foreground)' }">
          Unggah dokumen <strong :style="{ color: 'var(--foreground)' }">.pdf</strong> dan semua referensi
          divalidasi otomatis ke Crossref.
        </p>

        <input
          ref="fileInput"
          type="file"
          class="hidden"
          accept=".pdf,application/pdf"
          @change="onInputChange"
        />

        <!-- Dropzone -->
        <div
          class="dropzone mt-8"
          :class="{
            'dropzone-active': isDragging,
            'dropzone-filled': !!selectedFile,
            'dropzone-busy': uploading,
            'dropzone-error': !!errorMessage,
          }"
          :role="selectedFile ? undefined : 'button'"
          :tabindex="selectedFile ? undefined : 0"
          :aria-label="selectedFile ? undefined : 'Pilih file PDF'"
          @click="onZoneClick"
          @keydown.enter.prevent="onZoneClick"
          @keydown.space.prevent="onZoneClick"
          @dragenter.prevent="onDragEnter"
          @dragover.prevent
          @dragleave.prevent="onDragLeave"
          @drop.prevent="onDrop"
        >
          <template v-if="!selectedFile">
            <span class="dropzone-icon" aria-hidden="true"><Upload class="h-5 w-5" /></span>
            <p class="dropzone-title">
              {{ isDragging ? "Lepas file di sini" : "Ketuk atau seret file ke sini" }}
            </p>
            <p class="dropzone-sub">Mendukung PDF hingga {{ MAX_SIZE_MB }} MB</p>
            <span class="dropzone-pill">.PDF</span>
          </template>

          <template v-else>
            <span class="dropzone-icon dropzone-icon-file" aria-hidden="true"><FileText class="h-5 w-5" /></span>
            <p class="dropzone-title dropzone-file" :title="selectedFile.name">{{ selectedFile.name }}</p>
            <p class="dropzone-sub">{{ fileSizeLabel }}</p>
            <button type="button" class="dropzone-remove" :disabled="uploading" @click.stop="removeFile">
              Hapus &amp; pilih file lain
            </button>
          </template>
        </div>

        <p v-if="errorMessage" class="upload-error" role="alert">
          <CircleAlert class="mt-px h-4 w-4 flex-shrink-0" />
          <span>{{ errorMessage }}</span>
        </p>

        <button
          type="button"
          class="cta"
          :class="selectedFile ? 'cta-ready' : 'cta-idle'"
          :disabled="uploading"
          @click="handleCta"
        >
          <LoaderCircle v-if="uploading" class="h-4 w-4 animate-spin" />
          {{ ctaLabel }}
        </button>

        <p class="privacy-note">
          <span aria-hidden="true">🔒</span>
          Dokumenmu hanya bisa diakses oleh akunmu dan bisa dihapus kapan saja dari Riwayat
        </p>
      </section>

      <!-- Ilustrasi: di HP tampil di atas dropzone, di desktop di kanan -->
      <div class="hero-art order-1 lg:order-2">
        <img :src="heroImage" alt="" class="hero-img" draggable="false" />
      </div>
    </div>

    <!-- ================= Cara kerja ================= -->
    <div class="h-px my-10 sm:my-14" :style="{ background: 'var(--border)' }" />

    <section>
      <p class="section-label">CARA KERJA</p>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div
          v-for="step in howItWorks"
          :key="step.title"
          class="rounded-xl border p-5"
          :style="{ borderColor: 'var(--border)', background: 'var(--card)' }"
        >
          <div
            class="w-9 h-9 rounded-lg flex items-center justify-center mb-3"
            :style="{
              background: step.highlighted ? 'var(--primary)' : 'var(--secondary)',
              color: step.highlighted ? 'var(--primary-foreground)' : 'var(--muted-foreground)',
            }"
          >
            <svg v-if="step.icon === 'upload'" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M8 11V2M8 2L4.5 5.5M8 2l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M2.5 11v1.5a2 2 0 002 2h7a2 2 0 002-2V11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else-if="step.icon === 'loading'" class="animate-spin" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="22 15" />
            </svg>
            <svg v-else-if="step.icon === 'check'" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M3 8.5l3.2 3.2L13 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M3 2.5h7L13 6v7.5a1 1 0 01-1 1H3a1 1 0 01-1-1V3.5a1 1 0 011-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />
              <path d="M4.5 8h5M4.5 10.5h5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            </svg>
          </div>

          <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">{{ step.title }}</p>
          <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">{{ step.desc }}</p>
        </div>
      </div>
    </section>

    <!-- ================= Sumber database ================= -->
    <div class="h-px my-10 sm:my-14" :style="{ background: 'var(--border)' }" />

    <section>
      <p class="section-label">SUMBER DATABASE</p>

      <div class="grid grid-cols-1 gap-4">
        <div v-for="source in dataSources" :key="source.name" class="source-card">
          <span class="source-dot" aria-hidden="true" />
          <div>
            <p class="source-name">{{ source.name }}</p>
            <p class="source-desc">{{ source.desc }}</p>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>

<style scoped>
/* ---------- Teks hero ---------- */
.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0.75rem;
  border-radius: 9999px;
  background: var(--secondary);
  color: var(--secondary-foreground);
  font-size: 0.6875rem;
  font-weight: 700;
}
.hero-badge-dot {
  width: 0.3125rem;
  height: 0.3125rem;
  border-radius: 9999px;
  background: var(--accent);
}

.hero-title {
  color: var(--foreground);
  font-size: clamp(1.875rem, 1.2rem + 3vw, 3rem);
  line-height: 1.15;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.hero-accent {
  color: var(--accent);
}

/* ---------- Dropzone ---------- */
.dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  padding: 2rem 1.25rem;
  border: 1.5px dashed var(--border);
  border-radius: 1.25rem;
  background: var(--card);
  text-align: center;
  cursor: pointer;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
@media (min-width: 640px) {
  .dropzone {
    padding: 2.5rem 1.5rem;
  }
}
.dropzone:hover,
.dropzone:focus-visible {
  border-color: var(--accent);
  outline: none;
}
.dropzone:focus-visible {
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent);
}
.dropzone-filled {
  cursor: default;
  border-style: solid;
}
.dropzone-active {
  border-color: var(--accent);
  border-style: solid;
  background: var(--secondary);
}
.dropzone-busy {
  pointer-events: none;
  opacity: 0.75;
}
.dropzone-error {
  border-color: #f87171;
}

.dropzone-icon {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  margin-bottom: 0.75rem;
  border-radius: 0.875rem;
  background: var(--secondary);
  color: var(--muted-foreground);
}
.dropzone-icon-file {
  color: var(--accent);
}

.dropzone-title {
  color: var(--foreground);
  font-size: 0.875rem;
  font-weight: 800;
}
.dropzone-sub {
  color: var(--muted-foreground);
  font-size: 0.75rem;
}
.dropzone-file {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dropzone-pill {
  margin-top: 0.75rem;
  padding: 0.1875rem 0.625rem;
  border-radius: 9999px;
  background: #fee2e2;
  color: #b91c1c;
  font-size: 0.625rem;
  font-weight: 800;
  letter-spacing: 0.04em;
}
.dark .dropzone-pill {
  background: rgba(239, 68, 68, 0.16);
  color: #fca5a5;
}

.dropzone-remove {
  margin-top: 0.75rem;
  color: #b91c1c;
  font-size: 0.75rem;
  font-weight: 700;
}
.dropzone-remove:hover:not(:disabled) {
  text-decoration: underline;
}
.dropzone-remove:disabled {
  opacity: 0.5;
}
.dark .dropzone-remove {
  color: #f87171;
}

/* ---------- Pesan error ---------- */
.upload-error {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin-top: 0.75rem;
  padding: 0.625rem 0.875rem;
  border: 1px solid #fecaca;
  border-radius: 0.75rem;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 0.8125rem;
  font-weight: 600;
  line-height: 1.4;
}
.dark .upload-error {
  border-color: rgba(248, 113, 113, 0.3);
  background: rgba(239, 68, 68, 0.1);
  color: #fca5a5;
}

/* ---------- Tombol utama ---------- */
.cta {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  min-height: 3rem;
  margin-top: 1rem;
  border-radius: 0.75rem;
  border: 1px solid transparent;
  font-size: 0.875rem;
  font-weight: 700;
  transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}
.cta:focus-visible {
  outline: 2px solid var(--ring);
  outline-offset: 2px;
}
.cta:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}
.cta-ready {
  background: var(--accent);
  color: var(--accent-foreground);
}
.cta-ready:hover:not(:disabled) {
  filter: brightness(0.94);
}
/* Belum ada file: tombol outline yang tetap terbaca di dua mode */
.cta-idle {
  background: var(--card);
  border-color: var(--border);
  color: var(--foreground);
}
.cta-idle:hover:not(:disabled) {
  background: var(--muted);
}

.privacy-note {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  gap: 0.375rem;
  margin-top: 0.75rem;
  color: var(--muted-foreground);
  font-size: 0.75rem;
  line-height: 1.5;
  text-align: center;
}

/* ---------- Ilustrasi ---------- */
.hero-art {
  display: flex;
  justify-content: center;
  min-width: 0;
}
@media (min-width: 1024px) {
  .hero-art {
    justify-content: flex-end; /* desktop: rapat ke tepi kanan */
  }
}

.hero-img {
  display: block;
  width: 100%;
  max-width: 12rem; /* HP: kecil supaya dropzone tetap terlihat tanpa banyak scroll */
  height: auto;
  user-select: none;
}
@media (min-width: 480px) {
  .hero-img {
    max-width: 15rem;
  }
}
@media (min-width: 1080px) {
  .hero-img {
    max-width: 22rem;
  }
}

/* Mode gelap: gambar dibuat lebih besar */
/* Mode gelap: ukuran + posisi gambar */
.dark .hero-img {
  max-width: 18rem;
}
@media (min-width: 480px) {
  .dark .hero-img {
    max-width: 24rem;
  }
}
@media (min-width: 1024px) {
  .dark .hero-img {
    max-width: min(34rem, 100%);
    transform: translateX(2rem);
  }
}
@media (min-width: 1280px) {
  .dark .hero-img {
    max-width: min(40rem, 100%);
    transform: translateX(3rem);
  }
}
@media (min-width: 1800px) {
  .dark .hero-img {
    max-width: min(48rem, 100%);
    transform: translateX(4rem);
  }
}

/* ---------- Seksi bawah ---------- */
.section-label {
  margin-bottom: 1rem;
  color: var(--muted-foreground);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.source-card {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 1rem;
  border-radius: 0.75rem;
  background: #eaf0fd;
}
.source-dot {
  width: 0.5rem;
  height: 0.5rem;
  flex-shrink: 0;
  border-radius: 9999px;
  background: #2d5be3;
}
.source-name {
  color: #1e3a6e;
  font-size: 0.875rem;
  font-weight: 700;
}
.source-desc {
  margin-top: 0.125rem;
  color: #1e3a6e;
  opacity: 0.75;
  font-size: 0.75rem;
}
.dark .source-card {
  background: var(--secondary);
  border: 1px solid var(--border);
}
.dark .source-dot {
  background: var(--accent);
}
.dark .source-name,
.dark .source-desc {
  color: var(--secondary-foreground);
}
</style>