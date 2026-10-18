<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
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

function onDrop(event: DragEvent) {
  isDragging.value = false;
  if (uploading.value) return;
  const file = event.dataTransfer?.files?.[0];
  if (file) validateAndSet(file);
}

function openFilePicker() {
  fileInput.value?.click();
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

const dataSources = [
  {
    name: "Crossref",
    desc: "100M+ publikasi",
    dot: "#2d5be3",
    bg: "#eaf0fd",
    fg: "#1e3a6e",
  },
];
</script>

<template>
  <div class="max-w-3xl mx-auto px-6 py-16">
    <div class="flex flex-col items-center text-center">
      <span
        class="inline-flex items-center gap-2 text-xs font-semibold px-3.5 py-1.5 rounded-full mb-6"
        :style="{ background: 'var(--secondary)', color: 'var(--primary)' }"
      >
        <span class="w-1.5 h-1.5 rounded-full" :style="{ background: 'var(--accent)' }" />
        Validasi daftar pustaka otomatis
      </span>

      <h1
        class="text-4xl sm:text-5xl font-extrabold tracking-tight leading-tight"
        :style="{ color: 'var(--foreground)' }"
      >
        Periksa Daftar Pustaka<br />
        <span :style="{ color: 'var(--accent)' }">Tugas Akhir</span> Kamu
      </h1>

      <p class="mt-4 text-sm sm:text-base max-w-lg" :style="{ color: 'var(--muted-foreground)' }">
        Unggah dokumen <strong :style="{ color: 'var(--foreground)' }">.pdf</strong> dan semua referensi
        divalidasi otomatis ke Crossref.
      </p>
    </div>

    <div class="mt-10">
      <input
        ref="fileInput"
        type="file"
        class="hidden"
        accept=".pdf,application/pdf"
        @change="onInputChange"
      />

      <div
        class="relative flex flex-col items-center justify-center gap-4 rounded-2xl border-2 border-dashed px-6 py-14 text-center cursor-pointer transition-colors"
        :style="{
          borderColor: isDragging ? 'var(--accent)' : 'var(--border)',
          background: isDragging
            ? 'var(--secondary)'
            : 'radial-gradient(circle, var(--border) 1px, transparent 1px)',
          backgroundSize: '16px 16px',
          backgroundColor: 'var(--card)',
        }"
        @click="!selectedFile && !uploading && openFilePicker()"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
      >
        <template v-if="!selectedFile">
          <div
            class="w-14 h-14 rounded-2xl flex items-center justify-center"
            :style="{ background: 'var(--secondary)', color: 'var(--muted-foreground)' }"
          >
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
              <path
                d="M11 15V4M11 4L6 9M11 4l5 5"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
              <path
                d="M4 15.5v2a2 2 0 002 2h10a2 2 0 002-2v-2"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </div>

          <div>
            <p class="text-base font-bold" :style="{ color: 'var(--foreground)' }">Ketuk atau seret file ke sini</p>
            <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">
              Mendukung PDF hingga {{ MAX_SIZE_MB }} MB
            </p>
          </div>

          <div class="flex items-center gap-2">
            <span class="text-xs font-bold px-2.5 py-1 rounded-full" style="background: #fee2e2; color: #b91c1c">
              .PDF
            </span>
          </div>
        </template>

        <template v-else>
          <div
            class="w-14 h-14 rounded-2xl flex items-center justify-center"
            :style="{ background: 'var(--secondary)', color: 'var(--primary)' }"
          >
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
              <path
                d="M12.8 2.5H6a1.8 1.8 0 00-1.8 1.8v13.4A1.8 1.8 0 006 19.5h10a1.8 1.8 0 001.8-1.8V7.5L12.8 2.5z"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linejoin="round"
              />
              <path d="M12.8 2.5v5h5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
            </svg>
          </div>

          <div class="min-w-0">
            <p class="text-base font-bold truncate max-w-sm" :style="{ color: 'var(--foreground)' }">
              {{ selectedFile.name }}
            </p>
            <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">{{ fileSizeLabel }}</p>
          </div>

          <button
            class="text-xs font-semibold hover:underline disabled:opacity-50 disabled:no-underline"
            style="color: #b91c1c"
            :disabled="uploading"
            @click.stop="removeFile"
          >
            Hapus &amp; pilih file lain
          </button>
        </template>
      </div>

      <p v-if="errorMessage" class="text-sm text-center mt-3" style="color: #b91c1c">{{ errorMessage }}</p>

      <button
        class="w-full mt-5 rounded-xl py-3.5 text-sm font-bold text-white transition-colors disabled:cursor-not-allowed"
        :style="{ background: selectedFile ? 'var(--accent)' : '#a6b0c9', opacity: uploading ? 0.7 : 1 }"
        :disabled="uploading"
        @click="handleCta"
      >
        {{ ctaLabel }}
      </button>

      <p class="flex items-center justify-center gap-1.5 text-xs mt-3" :style="{ color: 'var(--muted-foreground)' }">
        🔒 Dokumenmu hanya bisa diakses oleh akunmu dan bisa dihapus kapan saja dari Riwayat
      </p>
    </div>

    <div class="h-px my-12" :style="{ background: 'var(--border)' }" />

    <div>
      <p class="text-xs font-bold tracking-widest mb-4" :style="{ color: 'var(--muted-foreground)' }">CARA KERJA</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
              color: step.highlighted ? 'white' : 'var(--muted-foreground)',
            }"
          >
            <svg v-if="step.icon === 'upload'" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M8 11V2M8 2L4.5 5.5M8 2l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M2.5 11v1.5a2 2 0 002 2h7a2 2 0 002-2V11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg
              v-else-if="step.icon === 'loading'"
              class="animate-spin"
              width="16"
              height="16"
              viewBox="0 0 16 16"
              fill="none"
            >
              <circle
                cx="8"
                cy="8"
                r="6"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-dasharray="22 15"
              />
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
    </div>

    <div class="h-px my-12" :style="{ background: 'var(--border)' }" />

    <div>
      <p class="text-xs font-bold tracking-widest mb-4" :style="{ color: 'var(--muted-foreground)' }">
        SUMBER DATABASE
      </p>

      <div class="grid grid-cols-1 gap-4">
        <div
          v-for="source in dataSources"
          :key="source.name"
          class="rounded-xl px-4 py-4 flex items-center gap-2.5"
          :style="{ background: source.bg }"
        >
          <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ background: source.dot }" />
          <div>
            <p class="text-sm font-bold" :style="{ color: source.fg }">{{ source.name }}</p>
            <p class="text-xs mt-0.5" :style="{ color: source.fg, opacity: 0.75 }">{{ source.desc }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>