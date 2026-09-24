<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import type { HistoryEntry } from "@/types";

const router = useRouter();

// TODO: ganti mock data ini dengan riwayat asli dari backend / store.
const history = ref<HistoryEntry[]>([
  {
    id: "h1",
    fileName: "Skripsi_Andi_Firmansyah_2024.pdf",
    uploadedAt: "14:32",
    date: "7 Sep 2024",
    timestamp: 1725693120000,
    totalRefs: 32,
    valid: 26,
    warning: 4,
    halu: 2,
    trustScore: 81,
    size: "2.4 MB",
    pages: 87,
  },
  {
    id: "h2",
    fileName: "TA_Sistem_Rekomendasi_Nurul.docx",
    uploadedAt: "09:15",
    date: "6 Sep 2024",
    timestamp: 1725599700000,
    totalRefs: 18,
    valid: 18,
    warning: 0,
    halu: 0,
    trustScore: 100,
    size: "1.1 MB",
    pages: 64,
  },
  {
    id: "h3",
    fileName: "Draft_TA_Bab1-3_Rizky.pdf",
    uploadedAt: "16:48",
    date: "4 Sep 2024",
    timestamp: 1725439680000,
    totalRefs: 45,
    valid: 29,
    warning: 8,
    halu: 8,
    trustScore: 64,
    size: "3.8 MB",
    pages: 102,
  },
  {
    id: "h4",
    fileName: "Proposal_TA_Dewi_Kusumawati.docx",
    uploadedAt: "11:20",
    date: "2 Sep 2024",
    timestamp: 1725261600000,
    totalRefs: 12,
    valid: 11,
    warning: 1,
    halu: 0,
    trustScore: 92,
    size: "0.8 MB",
    pages: 28,
  },
  {
    id: "h5",
    fileName: "Revisi_Skripsi_Final_Bagas.pdf",
    uploadedAt: "20:05",
    date: "31 Agu 2024",
    timestamp: 1725123900000,
    totalRefs: 55,
    valid: 38,
    warning: 9,
    halu: 8,
    trustScore: 69,
    size: "5.1 MB",
    pages: 134,
  },
]);

type ScoreFilter = "all" | "high" | "mid" | "low";

const searchQuery = ref("");
const scoreFilter = ref<ScoreFilter>("all");
const sortNewestFirst = ref(true);

const scoreFilters: { key: ScoreFilter; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "high", label: "\u2265 90%" },
  { key: "mid", label: "70-89%" },
  { key: "low", label: "< 70%" },
];

function matchesScoreFilter(entry: HistoryEntry) {
  if (scoreFilter.value === "all") return true;
  if (scoreFilter.value === "high") return entry.trustScore >= 90;
  if (scoreFilter.value === "mid") return entry.trustScore >= 70 && entry.trustScore < 90;
  return entry.trustScore < 70;
}

const filteredHistory = computed(() => {
  const query = searchQuery.value.trim().toLowerCase();
  let list = history.value.filter(
    (entry) => (!query || entry.fileName.toLowerCase().includes(query)) && matchesScoreFilter(entry),
  );
  list = [...list].sort((a, b) => (sortNewestFirst.value ? b.timestamp - a.timestamp : a.timestamp - b.timestamp));
  return list;
});

function scoreColor(score: number) {
  if (score >= 90) return "#16a34a";
  if (score >= 70) return "#d97706";
  return "#dc2626";
}

function fileKind(fileName: string) {
  return fileName.toLowerCase().endsWith(".pdf") ? "pdf" : "docx";
}

function toggleSort() {
  sortNewestFirst.value = !sortNewestFirst.value;
}

function openEntry(_entry: HistoryEntry) {
  // TODO: idealnya kirim entry.id agar ResultView memuat hasil sesuai riwayat ini.
  router.push({ name: "result" });
}

function removeEntry(id: string) {
  if (!confirm("Hapus riwayat dokumen ini?")) return;
  history.value = history.value.filter((entry) => entry.id !== id);
}

function clearAll() {
  if (!history.value.length) return;
  if (!confirm("Hapus seluruh riwayat analisis? Tindakan ini tidak bisa dibatalkan.")) return;
  history.value = [];
}

function downloadEntry(_entry: HistoryEntry) {
  // TODO: sambungkan ke endpoint export laporan per dokumen.
}
</script>

<template>
  <div class="max-w-7xl mx-auto px-6 py-10">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-extrabold" :style="{ color: 'var(--foreground)' }">Riwayat Analisis</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">
          {{ history.length }} dokumen telah dianalisis
        </p>
      </div>

      <button
        class="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-lg border"
        style="color: #b91c1c; border-color: #fecaca; background: white"
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
          v-for="filter in scoreFilters"
          :key="filter.key"
          class="text-sm font-semibold px-3.5 py-2 rounded-lg border transition-colors"
          :style="{
            borderColor: scoreFilter === filter.key ? 'var(--foreground)' : 'var(--border)',
            background: scoreFilter === filter.key ? 'white' : 'transparent',
            color: scoreFilter === filter.key ? 'var(--foreground)' : 'var(--muted-foreground)',
            boxShadow: scoreFilter === filter.key ? '0 1px 3px rgba(15,22,41,0.12)' : 'none',
          }"
          @click="scoreFilter = filter.key"
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

    <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr :style="{ background: 'var(--muted)' }">
              <th class="text-left font-bold px-5 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">DOKUMEN</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">REFERENSI</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">HALU</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">PERLU DICEK</th>
              <th class="text-left font-bold px-4 py-3 text-xs tracking-wider" :style="{ color: 'var(--muted-foreground)' }">KEPERCAYAAN</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="entry in filteredHistory"
              :key="entry.id"
              class="border-t"
              :style="{ borderColor: 'var(--border)' }"
            >
              <td class="px-5 py-4">
                <div class="flex items-center gap-3">
                  <div
                    class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                    :style="{ background: fileKind(entry.fileName) === 'pdf' ? '#fee2e2' : '#dbeafe' }"
                  >
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                      <path
                        d="M9.5 1.8H4a1.2 1.2 0 00-1.2 1.2v10a1.2 1.2 0 001.2 1.2h8a1.2 1.2 0 001.2-1.2V5.5L9.5 1.8z"
                        :stroke="fileKind(entry.fileName) === 'pdf' ? '#b91c1c' : '#1d4ed8'"
                        stroke-width="1.3"
                        stroke-linejoin="round"
                      />
                      <path d="M9.5 1.8v3.7h3.7" :stroke="fileKind(entry.fileName) === 'pdf' ? '#b91c1c' : '#1d4ed8'" stroke-width="1.3" stroke-linejoin="round" />
                    </svg>
                  </div>
                  <div class="min-w-0">
                    <p class="font-bold truncate max-w-xs" :style="{ color: 'var(--foreground)' }">{{ entry.fileName }}</p>
                    <p class="text-xs mt-0.5" :style="{ color: 'var(--muted-foreground)' }">
                      {{ entry.date }}, {{ entry.uploadedAt }} &middot; {{ entry.size }} &middot; {{ entry.pages }} hal.
                    </p>
                  </div>
                </div>
              </td>

              <td class="px-4 py-4 font-bold" :style="{ color: 'var(--foreground)' }">{{ entry.totalRefs }}</td>

              <td class="px-4 py-4">
                <span
                  class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full"
                  :style="{
                    background: entry.halu === 0 ? '#dcfce7' : '#fee2e2',
                    color: entry.halu === 0 ? '#15803d' : '#b91c1c',
                  }"
                >
                  {{ entry.halu === 0 ? "\u2713" : "\u2715" }} {{ entry.halu }}
                </span>
              </td>

              <td class="px-4 py-4">
                <span
                  v-if="entry.warning > 0"
                  class="inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full"
                  style="background: #fef3c7; color: #b45309"
                >
                  &#9888; {{ entry.warning }}
                </span>
                <span v-else class="text-sm" :style="{ color: 'var(--muted-foreground)' }">&mdash;</span>
              </td>

              <td class="px-4 py-4">
                <div class="flex items-center gap-2.5 min-w-[160px]">
                  <div class="flex-1 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--secondary)' }">
                    <div
                      class="h-full rounded-full"
                      :style="{ width: `${entry.trustScore}%`, background: scoreColor(entry.trustScore) }"
                    />
                  </div>
                  <span class="text-sm font-bold w-10 text-right" :style="{ color: scoreColor(entry.trustScore) }">
                    {{ entry.trustScore }}%
                  </span>
                </div>
              </td>

              <td class="px-4 py-4">
                <div class="flex items-center gap-2 justify-end">
                  <button
                    class="text-xs font-bold px-4 py-2 rounded-lg text-white"
                    :style="{ background: 'var(--primary)' }"
                    @click="openEntry(entry)"
                  >
                    Lihat
                  </button>
                  <button
                    class="w-8 h-8 rounded-lg border flex items-center justify-center"
                    :style="{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }"
                    aria-label="Unduh laporan"
                    @click="downloadEntry(entry)"
                  >
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                      <path d="M6.5 1.5v7M6.5 8.5L4 6M6.5 8.5L9 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M2 9.5v1.5a1 1 0 001 1h7a1 1 0 001-1V9.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </button>
                  <button
                    class="w-8 h-8 rounded-lg flex items-center justify-center"
                    style="background: #fee2e2; color: #b91c1c"
                    aria-label="Hapus riwayat"
                    @click="removeEntry(entry.id)"
                  >
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                      <path d="M2.5 3.8h8M5 3.8V2.6a.7.7 0 01.7-.7h1.6a.7.7 0 01.7.7v1.2M5 6v3.5M8 6v3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                      <path d="M3.2 3.8l.4 6.6a1 1 0 001 .9h4.8a1 1 0 001-.9l.4-6.6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="filteredHistory.length === 0">
              <td colspan="6" class="px-5 py-14 text-center text-sm" :style="{ color: 'var(--muted-foreground)' }">
                {{ searchQuery ? "Tidak ada dokumen yang cocok dengan pencarian." : "Belum ada riwayat analisis." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="filteredHistory.length" class="text-center text-sm mt-5" :style="{ color: 'var(--muted-foreground)' }">
      Menampilkan {{ filteredHistory.length }} dari {{ history.length }} dokumen
    </p>
  </div>
</template>