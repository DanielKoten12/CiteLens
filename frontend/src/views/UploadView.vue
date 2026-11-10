<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { CircleAlert, FileText, LoaderCircle, Upload } from "lucide-vue-next";
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

const heroWords = ["Laporan", "Tugas Akhir", "Proposal", "Skripsi"];
const typedWord = ref(heroWords[0]);
let heroWordIndex = 0;
let isDeleting = true;
let typingTimer: ReturnType<typeof setTimeout> | undefined;

function updateTypedWord() {
  if (isDeleting) {
    typedWord.value = typedWord.value.slice(0, -1);
    if (!typedWord.value) {
      heroWordIndex = (heroWordIndex + 1) % heroWords.length;
      isDeleting = false;
    }
  } else {
    const nextWord = heroWords[heroWordIndex];
    typedWord.value = nextWord.slice(0, typedWord.value.length + 1);
    if (typedWord.value === nextWord) isDeleting = true;
  }

  let delay = 105;
  if (!typedWord.value) delay = 300;
  else if (isDeleting) delay = typedWord.value === heroWords[heroWordIndex] ? 1600 : 65;
  typingTimer = window.setTimeout(updateTypedWord, delay);
}

onMounted(() => {
  if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    typingTimer = window.setTimeout(updateTypedWord, 1600);
  }
});

onBeforeUnmount(() => {
  clearTimeout(typingTimer);
});

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
    desc: "PDF dokumen kamu",
  },
  {
    icon: "loading",
    highlighted: false,
    title: "Ekstrak Dokumen",
    desc: "Semua daftar pustaka dan sitasi in-text terdeteksi otomatis",
  },
  {
    icon: "check",
    highlighted: false,
    title: "Validasi Daftar Pustaka",
    desc: "Dicocokkan dengan Metadata yang ada",
  },
  {
    icon: "report",
    highlighted: false,
    title: "Laporan Hasil",
    desc: "Export PDF dengan anotasi lengkap",
  },
];

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
          <span class="block"><span class="hero-accent typing-word">{{ typedWord }}</span> Kamu</span>
        </h1>

        <p class="mt-4 max-w-md text-sm sm:text-base leading-relaxed" :style="{ color: 'var(--muted-foreground)' }">
          Unggah dokumen <strong :style="{ color: 'var(--foreground)' }">.pdf</strong> dan semua referensi
          divalidasi otomatis.
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

      <!-- Ilustrasi berkas: di HP tampil di atas dropzone, di desktop di kanan -->
      <div class="hero-art order-1 lg:order-2">
        <svg class="hero-document" viewBox="0 0 400 340" role="img" aria-labelledby="hero-document-title">
          <title id="hero-document-title">Animasi berkas PDF yang sedang diperiksa</title>
          <circle class="document-glow" cx="200" cy="170" r="145" />
          <circle class="document-dot" cx="48" cy="83" r="5" />
          <circle class="document-dot" cx="344" cy="88" r="4" />
          <circle class="document-dot" cx="350" cy="304" r="5" />

          <g class="document-back" transform="rotate(-11 190 170)">
            <rect x="105" y="52" width="190" height="246" rx="18" />
          </g>
          <g class="document-back" transform="rotate(9 190 170)">
            <rect x="105" y="52" width="190" height="246" rx="18" />
          </g>

          <g class="document-front">
            <rect class="document-page" x="105" y="42" width="190" height="250" rx="18" />
            <rect class="document-icon-bg" x="125" y="63" width="42" height="42" rx="10" />
            <path class="document-icon" d="M138 72h11l6 6v18h-17z M149 72v7h6 M142 85h10 M142 90h10" />
            <rect class="document-pdf-tag" x="232" y="69" width="42" height="20" rx="10" />
            <text class="document-pdf-text" x="253" y="83" text-anchor="middle">PDF</text>

            <rect class="document-line-primary" x="125" y="122" width="130" height="9" rx="4.5" />
            <rect class="document-line" x="125" y="141" width="148" height="7" rx="3.5" />
            <rect class="document-line" x="125" y="156" width="108" height="7" rx="3.5" />
            <path class="document-divider" d="M125 179h150" />

            <circle class="document-number-bg" cx="137" cy="200" r="12" />
            <text class="document-number" x="137" y="204" text-anchor="middle">1</text>
            <rect class="document-line" x="157" y="196" width="80" height="7" rx="3.5" />
            <circle class="document-check-bg" cx="264" cy="200" r="11" />
            <path class="document-check" d="m259 200 3 3 6-7" />

            <circle class="document-number-bg" cx="137" cy="233" r="12" />
            <text class="document-number" x="137" y="237" text-anchor="middle">2</text>
            <rect class="document-line" x="157" y="229" width="65" height="7" rx="3.5" />
            <circle class="document-check-bg" cx="264" cy="233" r="11" />
            <path class="document-check" d="m259 233 3 3 6-7" />

            <rect class="document-scan" x="118" y="106" width="164" height="4" rx="2" />
          </g>

          <g class="document-badge">
            <rect class="document-badge-bg" x="223" y="266" width="145" height="45" rx="14" />
            <circle class="document-badge-icon" cx="246" cy="288" r="11" />
            <path class="document-check" d="m241 288 3 3 6-7" />
            <text class="document-badge-text" x="266" y="293">Terverifikasi</text>
          </g>
        </svg>
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
.typing-word {
  border-right: 0.06em solid currentColor;
  padding-right: 0.04em;
  animation: typing-cursor 0.8s step-end infinite;
}
@keyframes typing-cursor {
  50% { border-color: transparent; }
}
@media (prefers-reduced-motion: reduce) {
  .typing-word { animation: none; border-right: 0; padding-right: 0; }
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
  align-items: center;
  min-width: 0;
}
@media (min-width: 1024px) {
  .hero-art {
    justify-content: flex-end; /* desktop: rapat ke tepi kanan */
  }
}

.hero-document {
  display: block;
  width: 100%;
  max-width: 19rem;
  height: auto;
}
@media (min-width: 1024px) {
  .hero-document {
    max-width: 25rem;
  }
}
.document-glow { fill: var(--secondary); }
.document-dot { fill: var(--accent); opacity: 0.55; }
.document-back rect, .document-page, .document-badge-bg {
  fill: var(--card);
  stroke: var(--border);
  stroke-width: 2;
}
.document-back rect { fill: var(--secondary); }
.document-front { animation: document-float 4s ease-in-out infinite; }
.document-page { filter: drop-shadow(0 14px 18px rgb(20 46 100 / 12%)); }
.document-icon-bg, .document-pdf-tag, .document-number-bg { fill: var(--secondary); }
.document-icon { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.document-pdf-text, .document-number, .document-badge-text {
  fill: var(--primary);
  font: 700 12px system-ui, sans-serif;
}
.document-pdf-text { font-size: 10px; }
.document-line-primary { fill: var(--primary); opacity: 0.8; }
.document-line { fill: var(--border); }
.document-divider { stroke: var(--border); stroke-width: 2; }
.document-check-bg, .document-badge-icon { fill: var(--accent); }
.document-check { fill: none; stroke: white; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.document-scan { fill: var(--accent); opacity: 0.55; animation: document-scan 3.5s ease-in-out infinite; }
.document-badge { animation: document-float 4s ease-in-out 0.5s infinite; }
.document-badge-bg { filter: drop-shadow(0 8px 12px rgb(20 46 100 / 12%)); }
@keyframes document-float {
  50% { transform: translateY(-7px); }
}
@keyframes document-scan {
  0%, 100% { transform: translateY(0); opacity: 0; }
  15%, 85% { opacity: 0.55; }
  50% { transform: translateY(150px); }
}
@media (prefers-reduced-motion: reduce) {
  .document-front, .document-scan, .document-badge { animation: none; }
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
