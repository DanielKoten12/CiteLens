<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import type { ApiDocument, DocumentStatus } from "@/types/api";

const router = useRouter();
const PER_PAGE = 12;

const documents = ref<ApiDocument[]>([]);
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const loading = ref(false);
const errorMessage = ref("");
const query = ref("");

/* ---------- Pemuatan data ---------- */

let requestId = 0;

async function load() {
  const current = ++requestId;
  loading.value = true;
  errorMessage.value = "";
  try {
    const res = await documentsApi.list({
      page: page.value,
      per_page: PER_PAGE,
      q: query.value.trim() || undefined,
      sort: "-created_at",
    });
    if (current !== requestId) return; // respons usang, abaikan

    documents.value = res.data;
    total.value = res.meta.total;
    lastPage.value = res.meta.last_page;

    // Halaman terakhir mungkin kosong setelah penghapusan.
    if (page.value > res.meta.last_page && res.meta.last_page >= 1) {
      page.value = res.meta.last_page;
      await load();
      return;
    }
  } catch (e) {
    if (current !== requestId) return;
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal memuat dokumen.";
  } finally {
    if (current === requestId) loading.value = false;
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(query, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    load();
  }, 300);
});

function goToPage(n: number) {
  if (n < 1 || n > lastPage.value || n === page.value) return;
  page.value = n;
  load();
}

onMounted(load);
onBeforeUnmount(() => clearTimeout(searchTimer));

/* ---------- Tampilan kartu ---------- */

const cards = computed(() =>
  documents.value.map((doc) => {
    const s = doc.summary;
    const ready = doc.status === "completed" && !!s;
    const refs = s?.total_references ?? 0;
    return {
      doc,
      ready,
      valid: s?.valid ?? 0,
      suspicious: s?.suspicious ?? 0,
      invalid: (s?.invalid ?? 0) + (s?.not_found ?? 0),
      halu: s?.hallucination_citations ?? 0,
      // Rasio tampilan saja (valid / total referensi), bukan verdict dari backend.
      ratio: refs > 0 ? Math.round(((s?.valid ?? 0) / refs) * 100) : null,
    };
  }),
);

function ratioColor(score: number) {
  return score >= 90 ? "#16a34a" : score >= 70 ? "#d97706" : "#dc2626";
}

function ratioBg(score: number) {
  return score >= 90 ? "#dcfce7" : score >= 70 ? "#fef3c7" : "#fee2e2";
}

const STATUS_BADGE: Record<DocumentStatus, { label: string; bg: string; fg: string }> = {
  pending: { label: "Menunggu", bg: "#f1f5f9", fg: "#475569" },
  processing: { label: "Diproses", bg: "#dbeafe", fg: "#1d4ed8" },
  completed: { label: "Selesai", bg: "#dcfce7", fg: "#15803d" },
  failed: { label: "Gagal", bg: "#fee2e2", fg: "#b91c1c" },
};

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
}

function formatSize(bytes?: number) {
  if (!bytes) return "";
  const mb = bytes / (1024 * 1024);
  return mb < 0.1 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${mb.toFixed(1)} MB`;
}

/* ---------- Aksi ---------- */

function openDocument(doc: ApiDocument) {
  // Selesai -> hasil; yang lain -> halaman progres (menampilkan status / gagal).
  router.push({ name: doc.status === "completed" ? "result" : "processing", params: { id: doc.id } });
}

async function removeDocument(doc: ApiDocument) {
  if (!confirm("Hapus dokumen ini beserta hasil analisisnya?")) return;
  try {
    await documentsApi.remove(doc.id);
    await load();
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal menghapus dokumen.";
  }
}
</script>

<template>
  <main class="max-w-7xl mx-auto w-full px-6 py-10">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-7">
      <div>
        <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">ARSIP DOKUMEN</p>
        <h1 class="text-2xl font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">Dokumen Saya</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">
          Semua dokumen yang pernah kamu periksa tersimpan di sini.
        </p>
      </div>
      <button
        class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-white"
        :style="{ background: 'var(--primary)' }"
        @click="router.push({ name: 'upload' })"
      >
        <span class="text-base">+</span> Periksa Dokumen Baru
      </button>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
      <div class="relative w-full sm:max-w-sm">
        <span class="absolute left-3.5 top-1/2 -translate-y-1/2" :style="{ color: 'var(--muted-foreground)' }">
          &#128269;
        </span>
        <input
          v-model="query"
          placeholder="Cari nama file..."
          class="w-full h-11 rounded-xl border pl-10 pr-4 text-sm focus:outline-none"
          :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--foreground)' }"
        />
      </div>
      <p class="text-sm" :style="{ color: 'var(--muted-foreground)' }">{{ total }} dokumen</p>
    </div>

    <div
      v-if="errorMessage"
      class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 mb-5 text-sm"
      style="background: #fef2f2; border-color: #fecaca; color: #b91c1c"
    >
      <span>{{ errorMessage }}</span>
      <button class="font-bold underline underline-offset-2" @click="load">Coba lagi</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
      <article
        v-for="card in cards"
        :key="card.doc.id"
        class="rounded-2xl border p-5"
        :style="{ borderColor: 'var(--border)', background: 'var(--card)' }"
      >
        <div class="flex items-start justify-between gap-3">
          <div
            class="w-11 h-11 rounded-xl flex items-center justify-center font-extrabold text-xs"
            style="background: #fee2e2; color: #b91c1c"
          >
            PDF
          </div>

          <span
            v-if="card.ready && card.ratio !== null"
            class="text-xs font-bold px-2.5 py-1 rounded-full"
            :style="{ background: ratioBg(card.ratio), color: ratioColor(card.ratio) }"
          >
            {{ card.ratio }}% terverifikasi
          </span>
          <span
            v-else
            class="text-xs font-bold px-2.5 py-1 rounded-full"
            :style="{ background: STATUS_BADGE[card.doc.status].bg, color: STATUS_BADGE[card.doc.status].fg }"
          >
            {{ STATUS_BADGE[card.doc.status].label }}
            <template v-if="card.doc.status === 'processing'"> {{ card.doc.progress }}%</template>
          </span>
        </div>

        <h2 class="font-extrabold leading-snug mt-5 break-words" :style="{ color: 'var(--foreground)' }">
          {{ card.doc.name }}
        </h2>
        <p class="text-xs mt-2" :style="{ color: 'var(--muted-foreground)' }">
          {{ formatDate(card.doc.created_at) }}
          <template v-if="card.doc.file?.size"> &middot; {{ formatSize(card.doc.file.size) }}</template>
        </p>

        <div class="h-px my-5" :style="{ background: 'var(--border)' }" />

        <div v-if="card.ready" class="grid grid-cols-4 gap-2 text-center">
          <div>
            <p class="text-base font-extrabold" style="color: #15803d">{{ card.valid }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Valid</p>
          </div>
          <div>
            <p class="text-base font-extrabold" style="color: #b45309">{{ card.suspicious }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Dicek</p>
          </div>
          <div>
            <p class="text-base font-extrabold" style="color: #b91c1c">{{ card.invalid }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Tidak valid</p>
          </div>
          <div>
            <p class="text-base font-extrabold" style="color: #b91c1c">{{ card.halu }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Sitasi halu</p>
          </div>
        </div>
        <p
          v-else-if="card.doc.status === 'failed'"
          class="text-xs text-center"
          :style="{ color: card.doc.error ? '#b91c1c' : 'var(--muted-foreground)' }"
        >
          {{ card.doc.error || "Analisis gagal." }}
        </p>
        <p v-else class="text-xs text-center" :style="{ color: 'var(--muted-foreground)' }">
          Analisis belum selesai.
        </p>

        <div class="flex gap-2 mt-5">
          <button
            class="flex-1 rounded-xl py-2.5 text-xs font-bold text-white"
            :style="{ background: 'var(--primary)' }"
            @click="openDocument(card.doc)"
          >
            {{ card.doc.status === "completed" ? "Buka Hasil" : "Lihat Progres" }}
          </button>
          <button
            class="w-10 rounded-xl flex items-center justify-center"
            style="background: #fee2e2; color: #b91c1c"
            aria-label="Hapus dokumen"
            @click="removeDocument(card.doc)"
          >
            &#128465;
          </button>
        </div>
      </article>
    </div>

    <div
      v-if="loading && cards.length === 0"
      class="rounded-2xl border py-16 text-center"
      :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--muted-foreground)' }"
    >
      Memuat dokumen...
    </div>

    <div
      v-else-if="!loading && cards.length === 0 && !errorMessage"
      class="rounded-2xl border py-16 text-center"
      :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--muted-foreground)' }"
    >
      {{ query ? "Tidak ada dokumen yang cocok." : "Belum ada dokumen." }}
    </div>

    <div v-if="lastPage > 1" class="flex items-center justify-center gap-3 mt-8">
      <button
        class="text-sm font-semibold px-3.5 py-2 rounded-lg border disabled:opacity-40"
        :style="{ borderColor: 'var(--border)', color: 'var(--foreground)' }"
        :disabled="page <= 1 || loading"
        @click="goToPage(page - 1)"
      >
        Sebelumnya
      </button>
      <span class="text-sm" :style="{ color: 'var(--muted-foreground)' }">{{ page }} / {{ lastPage }}</span>
      <button
        class="text-sm font-semibold px-3.5 py-2 rounded-lg border disabled:opacity-40"
        :style="{ borderColor: 'var(--border)', color: 'var(--foreground)' }"
        :disabled="page >= lastPage || loading"
        @click="goToPage(page + 1)"
      >
        Berikutnya
      </button>
    </div>
  </main>
</template>