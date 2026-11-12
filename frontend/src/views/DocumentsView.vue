<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { Plus, Search, Trash2 } from "lucide-vue-next";
import ConfirmDialog from "@/components/common/ConfirmDialog.vue";
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

/** Level skor dipakai untuk class CSS, supaya warnanya punya versi mode gelap. */
function ratioLevel(score: number): "good" | "mid" | "bad" {
  return score >= 90 ? "good" : score >= 70 ? "mid" : "bad";
}

const STATUS_LABEL: Record<DocumentStatus, string> = {
  pending: "Menunggu",
  processing: "Diproses",
  completed: "Selesai",
  failed: "Gagal",
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

/* ---------- Dialog konfirmasi hapus ---------- */

const pendingDoc = ref<ApiDocument | null>(null);
// Dipisah dari pendingDoc supaya teks dialog tidak berubah saat animasi menutup.
const dialogOpen = ref(false);
const deleting = ref(false);

function removeDocument(doc: ApiDocument) {
  pendingDoc.value = doc;
  dialogOpen.value = true;
}

function cancelDelete() {
  if (deleting.value) return;
  dialogOpen.value = false;
}

async function confirmDelete() {
  const doc = pendingDoc.value;
  if (!doc || deleting.value) return;
  deleting.value = true;
  errorMessage.value = "";
  try {
    await documentsApi.remove(doc.id);
    await load();
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal menghapus dokumen.";
  } finally {
    deleting.value = false;
    dialogOpen.value = false;
  }
}
</script>

<template>
  <main class="max-w-7xl mx-auto w-full px-4 sm:px-6 py-8 sm:py-10">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-7">
      <div class="min-w-0">
        <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">ARSIP DOKUMEN</p>
        <h1 class="text-2xl font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">Dokumen Saya</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">
          Semua dokumen yang pernah kamu periksa tersimpan di sini.
        </p>
      </div>
      <button
        class="new-btn"
        @click="router.push({ name: 'upload' })"
      >
        <Plus class="h-4 w-4" :stroke-width="2.5" />
        Periksa Dokumen Baru
      </button>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 mb-5">
      <div class="relative w-full sm:max-w-sm">
        <Search
          class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2"
          :style="{ color: 'var(--muted-foreground)' }"
        />
        <input
          v-model="query"
          placeholder="Cari nama file..."
          class="search-input w-full h-11 rounded-xl border pl-10 pr-4 text-sm focus:outline-none"
          :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--foreground)' }"
        />
      </div>
      <p class="text-sm" :style="{ color: 'var(--muted-foreground)' }">{{ total }} dokumen</p>
    </div>

    <div v-if="errorMessage" class="error-box" role="alert">
      <span>{{ errorMessage }}</span>
      <button class="font-bold underline underline-offset-2 flex-shrink-0" @click="load">Coba lagi</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
      <article
        v-for="card in cards"
        :key="card.doc.id"
        class="rounded-2xl border p-5"
        :style="{ borderColor: 'var(--border)', background: 'var(--card)' }"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="pdf-icon">PDF</div>

          <span
            v-if="card.ready && card.ratio !== null"
            class="badge"
            :class="`badge-${ratioLevel(card.ratio)}`"
          >
            {{ card.ratio }}% terverifikasi
          </span>
          <span v-else class="badge" :class="`badge-status-${card.doc.status}`">
            {{ STATUS_LABEL[card.doc.status] }}
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
            <p class="stat stat-good">{{ card.valid }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Valid</p>
          </div>
          <div>
            <p class="stat stat-warn">{{ card.suspicious }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Dicek</p>
          </div>
          <div>
            <p class="stat stat-bad">{{ card.invalid }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Tidak valid</p>
          </div>
          <div>
            <p class="stat stat-bad">{{ card.halu }}</p>
            <p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Sitasi halu</p>
          </div>
        </div>
        <p
          v-else-if="card.doc.status === 'failed'"
          class="text-xs text-center"
          :class="card.doc.error ? 'text-fail' : ''"
          :style="card.doc.error ? undefined : { color: 'var(--muted-foreground)' }"
        >
          {{ card.doc.error || "Analisis gagal." }}
        </p>
        <p v-else class="text-xs text-center" :style="{ color: 'var(--muted-foreground)' }">
          Analisis belum selesai.
        </p>

        <div class="flex gap-2 mt-5">
          <button class="open-btn" @click="openDocument(card.doc)">
            {{ card.doc.status === "completed" ? "Buka Hasil" : "Lihat Progres" }}
          </button>
          <button class="delete-btn" aria-label="Hapus dokumen" @click="removeDocument(card.doc)">
            <Trash2 class="h-4 w-4" />
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

    <ConfirmDialog
      :open="dialogOpen"
      title="Hapus dokumen ini?"
      description="Dokumen beserta hasil analisisnya akan dihapus dan tidak bisa dikembalikan."
      :item-name="pendingDoc?.name ?? ''"
      confirm-label="Hapus"
      :loading="deleting"
      @confirm="confirmDelete"
      @cancel="cancelDelete"
    />
  </main>
</template>

<style scoped>
/* ---------- Tombol ---------- */
.new-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  border-radius: 0.75rem;
  background: var(--primary);
  color: var(--primary-foreground);
  font-size: 0.875rem;
  font-weight: 700;
}
@media (max-width: 639px) {
  .new-btn {
    width: 100%;
  }
}

.open-btn {
  flex: 1;
  padding: 0.625rem 0;
  border-radius: 0.75rem;
  background: var(--primary);
  color: var(--primary-foreground);
  font-size: 0.75rem;
  font-weight: 700;
}

.delete-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  border-radius: 0.75rem;
  background: #fee2e2;
  color: #b91c1c;
  transition: background-color 0.15s ease;
}
.delete-btn:hover {
  background: #fecaca;
}
.dark .delete-btn {
  background: rgba(239, 68, 68, 0.16);
  color: #fca5a5;
}
.dark .delete-btn:hover {
  background: rgba(239, 68, 68, 0.26);
}

/* ---------- Pencarian ---------- */
.search-input:focus {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent);
}

/* ---------- Kotak error ---------- */
.error-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
  padding: 0.75rem 1rem;
  border: 1px solid #fecaca;
  border-radius: 0.75rem;
  background: #fef2f2;
  color: #b91c1c;
  font-size: 0.875rem;
}
.dark .error-box {
  border-color: rgba(248, 113, 113, 0.3);
  background: rgba(239, 68, 68, 0.1);
  color: #fca5a5;
}

/* ---------- Ikon PDF ---------- */
.pdf-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.75rem;
  background: #fee2e2;
  color: #b91c1c;
  font-size: 0.75rem;
  font-weight: 800;
}
.dark .pdf-icon {
  background: rgba(239, 68, 68, 0.16);
  color: #fca5a5;
}

/* ---------- Badge ---------- */
.badge {
  padding: 0.25rem 0.625rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
}

.badge-good,
.badge-status-completed {
  background: #dcfce7;
  color: #15803d;
}
.badge-mid {
  background: #fef3c7;
  color: #b45309;
}
.badge-bad,
.badge-status-failed {
  background: #fee2e2;
  color: #b91c1c;
}
.badge-status-pending {
  background: #f1f5f9;
  color: #475569;
}
.badge-status-processing {
  background: #dbeafe;
  color: #1d4ed8;
}

.dark .badge-good,
.dark .badge-status-completed {
  background: rgba(34, 197, 94, 0.15);
  color: #4ade80;
}
.dark .badge-mid {
  background: rgba(245, 158, 11, 0.15);
  color: #fbbf24;
}
.dark .badge-bad,
.dark .badge-status-failed {
  background: rgba(239, 68, 68, 0.16);
  color: #fca5a5;
}
.dark .badge-status-pending {
  background: rgba(148, 163, 184, 0.16);
  color: #cbd5e1;
}
.dark .badge-status-processing {
  background: rgba(59, 130, 246, 0.18);
  color: #93c5fd;
}

/* ---------- Angka ringkasan ---------- */
.stat {
  font-size: 1rem;
  font-weight: 800;
}
.stat-good {
  color: #15803d;
}
.stat-warn {
  color: #b45309;
}
.stat-bad {
  color: #b91c1c;
}
.dark .stat-good {
  color: #4ade80;
}
.dark .stat-warn {
  color: #fbbf24;
}
.dark .stat-bad {
  color: #f87171;
}

.text-fail {
  color: #b91c1c;
}
.dark .text-fail {
  color: #fca5a5;
}
</style>