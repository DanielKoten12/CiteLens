<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import type { ApiDocument, DocumentStatus } from "@/types/api";

const router = useRouter();
const PER_PAGE = 15;

const documents = ref<ApiDocument[]>([]);
const total = ref(0);
const lastPage = ref(1);
const page = ref(1);
const loading = ref(false);
const errorMessage = ref("");
const downloadingId = ref<string | null>(null);

type StatusFilter = "all" | "completed" | "processing" | "failed";

const searchQuery = ref("");
const statusFilter = ref<StatusFilter>("all");
const sortNewestFirst = ref(true);

const statusFilters: { key: StatusFilter; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "completed", label: "Selesai" },
  { key: "processing", label: "Diproses" },
  { key: "failed", label: "Gagal" },
];

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
      q: searchQuery.value.trim() || undefined,
      status: statusFilter.value === "all" ? undefined : statusFilter.value,
      sort: sortNewestFirst.value ? "-created_at" : "created_at",
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
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal memuat riwayat.";
  } finally {
    if (current === requestId) loading.value = false;
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(searchQuery, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    load();
  }, 300);
});

watch([statusFilter, sortNewestFirst], () => {
  page.value = 1;
  load();
});

function goToPage(n: number) {
  if (n < 1 || n > lastPage.value || n === page.value) return;
  page.value = n;
  load();
}

onMounted(load);
onBeforeUnmount(() => clearTimeout(searchTimer));

/* ---------- Tampilan baris ---------- */

const rows = computed(() =>
  documents.value.map((doc) => {
    const s = doc.summary;
    const ready = doc.status === "completed" && !!s;
    const refs = s?.total_references ?? 0;
    return {
      doc,
      ready,
      refs,
      invalid: (s?.invalid ?? 0) + (s?.not_found ?? 0),
      suspicious: s?.suspicious ?? 0,
      halu: s?.hallucination_citations ?? 0,
      // Rasio tampilan saja (valid / total referensi), bukan verdict dari backend.
      ratio: refs > 0 ? Math.round(((s?.valid ?? 0) / refs) * 100) : null,
    };
  }),
);

function ratioColor(score: number) {
  if (score >= 90) return "#16a34a";
  if (score >= 70) return "#d97706";
  return "#dc2626";
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

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

function formatSize(bytes?: number) {
  if (!bytes) return "";
  const mb = bytes / (1024 * 1024);
  return mb < 0.1 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${mb.toFixed(1)} MB`;
}

function toggleSort() {
  sortNewestFirst.value = !sortNewestFirst.value;
}

/* ---------- Aksi ---------- */

function openEntry(doc: ApiDocument) {
  // Dokumen selesai -> hasil; yang lain -> halaman progres (menampilkan status/gagal).
  router.push({ name: doc.status === "completed" ? "result" : "processing", params: { id: doc.id } });
}

async function removeEntry(doc: ApiDocument) {
  if (!confirm("Hapus riwayat dokumen ini?")) return;
  try {
    await documentsApi.remove(doc.id);
    await load();
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal menghapus dokumen.";
  }
}

async function clearAll() {
  if (!total.value) return;
  if (!confirm("Hapus seluruh riwayat analisis? Tindakan ini tidak bisa dibatalkan.")) return;
  try {
    await documentsApi.purge();
    page.value = 1;
    await load();
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : "Gagal menghapus riwayat.";
  }
}

/** Pakai laporan yang sudah jadi; kalau belum ada, buat lalu tunggu selesai. */
async function getReportUrl(docId: string): Promise<string> {
  const existing = await documentsApi.reports(docId);
  const ready = existing.data.find((r) => r.status === "completed" && r.download_url);
  if (ready?.download_url) return ready.download_url;

  const created = await documentsApi.createReport(docId);
  for (let i = 0; i < 30; i++) {
    await new Promise((resolve) => setTimeout(resolve, 2000));
    const { data } = await documentsApi.report(created.data.id);
    if (data.status === "completed" && data.download_url) return data.download_url;
    if (data.status === "failed") throw new Error(data.error ?? "Pembuatan laporan gagal.");
  }
  throw new Error("Laporan belum siap. Coba lagi beberapa saat lagi.");
}

async function downloadEntry(doc: ApiDocument) {
  if (doc.status !== "completed" || downloadingId.value) return;
  downloadingId.value = doc.id;
  errorMessage.value = "";
  try {
    window.open(await getReportUrl(doc.id), "_blank");
  } catch (e) {
    errorMessage.value = e instanceof Error ? e.message : "Gagal menyiapkan laporan.";
  } finally {
    downloadingId.value = null;
  }
}
</script>

<template>
  <div class="max-w-7xl mx-auto px-6 py-10">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-extrabold" :style="{ color: 'var(--foreground)' }">Riwayat Analisis</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">
          {{ total }} dokumen
        </p>
      </div>

      <button
        class="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-lg border disabled:opacity-50"
        style="color: #b91c1c; border-color: #fecaca; background: white"
        :disabled="!total"
        @click="clearAll"
      >
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
          <path d="M2.5 4h9M5.5 4V2.8a.8.8 0 01.8-.8h1.4a.8.8 0 01.8.8V4M5.5 6.5v4M8.5 6.5v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M3.2 4l.5 7.3a1 1 0 001 .9h4.6a1 1 0 001-.9l.5-7.3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Hapus Semua
      </button>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-5">
      <div class="relative flex-1 min-w-[220px]">
        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-base" :style="{ color: 'var(--muted-foreground)' }">
          &#128269;
        </span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Cari nama file..."
          class="w-full h-11 rounded-xl border pl-10 pr-4 text-sm focus:outline-none"
          :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--foreground)' }"
        />
      </div>

      <div class="flex items-center gap-1.5 flex-wrap">
        <button
          v-for="filter in statusFilters"
          :key="filter.key"
          class="text-sm font-semibold px-3.5 py-2 rounded-lg border transition-colors"
          :style="{
            borderColor: statusFilter === filter.key ? 'var(--foreground)' : 'var(--border)',
            background: statusFilter === filter.key ? 'white' : 'transparent',
            color: statusFilter === filter.key ? 'var(--foreground)' : 'var(--muted-foreground)',
            boxShadow: statusFilter === filter.key ? '0 1px 3px rgba(15,22,41,0.12)' : 'none',
          }"
          @click="statusFilter = filter.key"
        >
          {{ filter.label }}
        </button>
      </div>

      <button
        class="flex items-center gap-2 text-sm font-semibold px-3.5 py-2 rounded-lg border"
        :style="{ borderColor: 'var(--foreground)', color: 'var(--foreground)', background: 'white' }"
        @click="toggleSort"
      >
        <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
          <path d="M3.5 2v9M3.5 2L1.5 4M3.5 2l2 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M9.5 11V2M9.5 11l-2-2M9.5 11l2-2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ sortNewestFirst ? "Terbaru" : "Terlama" }}
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 mb-5 text-sm"
      style="background: #fef2f2; border-color: #fecaca; color: #b91c1c"
    >
      <span>{{ errorMessage }}</span>
      <button class="font-bold underline underline-offset-2" @click="load">Coba lagi</button>
    </div>

    <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr :style="{ background: 'var(--muted)' }">
              <th class="text-left font-bold px-5 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">DOKUMEN</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">REFERENSI</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">TIDAK VALID</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">PERLU DICEK</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">SITASI HALU</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">TERVERIFIKASI</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="row in rows"
              :key="row.doc.id"
              class="border-t"
              :style="{ borderColor: 'var(--border)' }"
            >
              <td class="px-5 py-4">
                <div class="flex items-center gap-3">
                  <div
                    class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                    style="background: #fee2e2"
                  >
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                      <path
                        d="M9.5 1.8H4a1.2 1.2 0 00-1.2 1.2v10a1.2 1.2 0 001.2 1.2h8a1.2 1.2 0 001.2-1.2V5.5L9.5 1.8z"
                        stroke="#b91c1c"
                        stroke-width="1.3"
                        stroke-linejoin="round"
                      />
                      <path d="M9.5 1.8v3.7h3.7" stroke="#b91c1c" stroke-width="1.3" stroke-linejoin="round" />
                    </svg>
                  </div>
                  <div class="min-w-0">
                    <p class="font-bold truncate max-w-xs" :style="{ color: 'var(--foreground)' }">{{ row.doc.name }}</p>
                    <p class="text-xs mt-0.5" :style="{ color: 'var(--muted-foreground)' }">
                      {{ formatDate(row.doc.created_at) }}, {{ formatTime(row.doc.created_at) }}
                      <template v-if="row.doc.file?.size"> &middot; {{ formatSize(row.doc.file.size) }}</template>
                    </p>
                  </div>
                </div>
              </td>

              <template v-if="row.ready">
                <td class="px-4 py-4 font-bold" :style="{ color: 'var(--foreground)' }">{{ row.refs }}</td>

                <td class="px-4 py-4">
                  <span
                    class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full"
                    :style="{
                      background: row.invalid === 0 ? '#dcfce7' : '#fee2e2',
                      color: row.invalid === 0 ? '#15803d' : '#b91c1c',
                    }"
                  >
                    {{ row.invalid === 0 ? "\u2713" : "\u2715" }} {{ row.invalid }}
                  </span>
                </td>

                <td class="px-4 py-4">
                  <span
                    v-if="row.suspicious > 0"
                    class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full"
                    style="background: #fef3c7; color: #b45309"
                  >
                    &#9888; {{ row.suspicious }}
                  </span>
                  <span v-else class="text-sm" :style="{ color: 'var(--muted-foreground)' }">&mdash;</span>
                </td>

                <td class="px-4 py-4">
                  <span
                    class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full"
                    :style="{
                      background: row.halu === 0 ? '#dcfce7' : '#fee2e2',
                      color: row.halu === 0 ? '#15803d' : '#b91c1c',
                    }"
                  >
                    {{ row.halu === 0 ? "\u2713" : "\u2715" }} {{ row.halu }}
                  </span>
                </td>

                <td class="px-4 py-4">
                  <div v-if="row.ratio !== null" class="flex items-center gap-2.5 min-w-[160px]">
                    <div class="flex-1 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--secondary)' }">
                      <div
                        class="h-full rounded-full"
                        :style="{ width: `${row.ratio}%`, background: ratioColor(row.ratio) }"
                      />
                    </div>
                    <span class="text-sm font-bold w-10 text-right" :style="{ color: ratioColor(row.ratio) }">
                      {{ row.ratio }}%
                    </span>
                  </div>
                  <span v-else class="text-sm" :style="{ color: 'var(--muted-foreground)' }">&mdash;</span>
                </td>
              </template>

              <td v-else colspan="5" class="px-4 py-4">
                <div class="flex items-center gap-3">
                  <span
                    class="inline-flex items-center text-xs font-bold px-2.5 py-1 rounded-full"
                    :style="{ background: STATUS_BADGE[row.doc.status].bg, color: STATUS_BADGE[row.doc.status].fg }"
                  >
                    {{ STATUS_BADGE[row.doc.status].label }}
                  </span>
                  <span
                    v-if="row.doc.status === 'processing'"
                    class="text-xs"
                    :style="{ color: 'var(--muted-foreground)' }"
                  >
                    {{ row.doc.progress }}%
                  </span>
                  <span
                    v-if="row.doc.status === 'failed' && row.doc.error"
                    class="text-xs truncate max-w-xs"
                    style="color: #b91c1c"
                  >
                    {{ row.doc.error }}
                  </span>
                </div>
              </td>

              <td class="px-4 py-4">
                <div class="flex items-center gap-2 justify-end">
                  <button
                    class="text-xs font-bold px-4 py-2 rounded-lg text-white"
                    :style="{ background: 'var(--primary)' }"
                    @click="openEntry(row.doc)"
                  >
                    Lihat
                  </button>
                  <button
                    class="w-8 h-8 rounded-lg border flex items-center justify-center disabled:opacity-40"
                    :style="{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }"
                    aria-label="Unduh laporan"
                    :disabled="row.doc.status !== 'completed' || downloadingId !== null"
                    @click="downloadEntry(row.doc)"
                  >
                    <svg
                      v-if="downloadingId === row.doc.id"
                      class="animate-spin"
                      width="13"
                      height="13"
                      viewBox="0 0 16 16"
                      fill="none"
                    >
                      <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="22 15" />
                    </svg>
                    <svg v-else width="13" height="13" viewBox="0 0 13 13" fill="none">
                      <path d="M6.5 1.5v7M6.5 8.5L4 6M6.5 8.5L9 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M2 9.5v1.5a1 1 0 001 1h7a1 1 0 001-1V9.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </button>
                  <button
                    class="w-8 h-8 rounded-lg flex items-center justify-center"
                    style="background: #fee2e2; color: #b91c1c"
                    aria-label="Hapus riwayat"
                    @click="removeEntry(row.doc)"
                  >
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                      <path d="M2.5 3.8h8M5 3.8V2.6a.7.7 0 01.7-.7h1.6a.7.7 0 01.7.7v1.2M5 6v3.5M8 6v3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M3.2 3.8l.4 6.6a1 1 0 001 .9h4.8a1 1 0 001-.9l.4-6.6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="loading && rows.length === 0">
              <td colspan="7" class="px-5 py-14 text-center text-sm" :style="{ color: 'var(--muted-foreground)' }">
                Memuat riwayat...
              </td>
            </tr>

            <tr v-else-if="!loading && rows.length === 0 && !errorMessage">
              <td colspan="7" class="px-5 py-14 text-center text-sm" :style="{ color: 'var(--muted-foreground)' }">
                {{
                  searchQuery || statusFilter !== "all"
                    ? "Tidak ada dokumen yang cocok dengan pencarian."
                    : "Belum ada riwayat analisis."
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="rows.length" class="flex items-center justify-between mt-5">
      <p class="text-sm" :style="{ color: 'var(--muted-foreground)' }">
        Menampilkan {{ rows.length }} dari {{ total }} dokumen
      </p>

      <div v-if="lastPage > 1" class="flex items-center gap-2">
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
    </div>
  </div>
</template>