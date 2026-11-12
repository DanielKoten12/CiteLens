<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import PdfHighlightViewer from "@/components/viewer/PdfHighlightViewer.vue";
import { useDocumentHighlights } from "@/composables/useDocumentHighlights";
import { ApiError } from "@/lib/api";
import { reportUrl } from "@/lib/documents";
import { metadataSourceLabel } from "@/lib/metadataSources";
import type { ApiCitation, ApiReference, Candidate, FindingStatus } from "@/types/api";
import type { HighlightItem } from "@/types/viewer";

const props = defineProps<{ id?: string }>();
const router = useRouter();

const viewer = ref<InstanceType<typeof PdfHighlightViewer> | null>(null);
const {
  document: documentData,
  references,
  citations,
  highlights,
  loading,
  error,
  load,
} = useDocumentHighlights(props.id);

/* ---------- Pemetaan status backend -> bucket tampilan ---------- */

/**
 * Bucket hanya untuk tampilan. Verdict tetap milik backend
 * (`reference_findings.status`); frontend tidak memutuskan valid/invalid.
 */
type Bucket = "valid" | "warning" | "halu" | "pending";

const BUCKET: Record<FindingStatus, Bucket> = {
  valid: "valid",
  suspicious: "warning",
  invalid: "halu",
  not_found: "halu",
  pending: "pending",
};

const statusMeta: Record<Bucket, { label: string; bg: string; fg: string }> = {
  valid: { label: "Valid", bg: "#dcfce7", fg: "#15803d" },
  warning: { label: "Perlu Dicek", bg: "#fef3c7", fg: "#b45309" },
  halu: { label: "Tidak Valid", bg: "#fee2e2", fg: "#b91c1c" },
  pending: { label: "Belum Dicek", bg: "#e2e8f0", fg: "#475569" },
};

/**
 * `citation.status` sudah mencakup `unresolved` di `docs/API_SPEC.md` §2.6, tetapi
 * `types/api.ts` belum memuatnya, jadi dipetakan sebagai Record<string, string>.
 */
const citationStatusLabel: Record<string, string> = {
  valid: "valid",
  unreliable: "tidak reliabel",
  pending: "menunggu",
  hallucination: "tanpa padanan",
  unresolved: "belum pasti",
};

/* ---------- Baris referensi ---------- */

type ReferenceRow = {
  id: string;
  index: number;
  bucket: Bucket;
  findingStatus: FindingStatus;
  title: string;
  authors: string | null;
  year: number | null;
  doi: string | null;
  reason: string;
  confidence: number;
  isManual: boolean;
  candidate: Candidate | null;
  citations: ApiCitation[];
};

/** Sitasi yang sudah dipasangkan ke referensi, dikelompokkan per id referensi. */
const citationsByReference = computed(() => {
  const grouped = new Map<string, ApiCitation[]>();
  for (const citation of citations.value) {
    const referenceId = citation.reference?.id;
    if (!referenceId) continue;
    const existing = grouped.get(referenceId);
    if (existing) existing.push(citation);
    else grouped.set(referenceId, [citation]);
  }
  return grouped;
});

function pickedCandidate(reference: ApiReference): Candidate | null {
  const finding = reference.finding;
  if (!finding?.candidates?.length) return null;
  return (
    finding.candidates.find((candidate) => candidate.id === finding.selected_candidate_id) ??
    finding.candidates[0] ??
    null
  );
}

function rowTitle(reference: ApiReference): string {
  if (reference.title) return reference.title;
  const raw = reference.raw_text.trim();
  return raw.length > 140 ? `${raw.slice(0, 140)}…` : raw;
}

const rows = computed<ReferenceRow[]>(() =>
  references.value.map((reference, position) => {
    const finding = reference.finding;
    const status: FindingStatus = finding?.status ?? "pending";
    return {
      id: reference.id,
      index: position + 1,
      bucket: BUCKET[status],
      findingStatus: status,
      title: rowTitle(reference),
      authors: reference.authors,
      year: reference.publication_year,
      doi: reference.doi,
      reason: finding?.reason ?? "",
      confidence: finding?.confidence ?? 0,
      isManual: finding?.is_manual === true,
      candidate: pickedCandidate(reference),
      citations: citationsByReference.value.get(reference.id) ?? [],
    };
  }),
);

const counts = computed(() => {
  const totals = { total: rows.value.length, valid: 0, warning: 0, halu: 0, pending: 0 };
  for (const row of rows.value) totals[row.bucket] += 1;
  return totals;
});

const trustScore = computed(() => {
  if (counts.value.total === 0) return 0;
  // Rasio tampilan (bukan verdict): valid + 50% perlu dicek, sama dengan halaman Profil.
  const weighted = counts.value.valid + counts.value.warning * 0.5;
  return Math.round((weighted / counts.value.total) * 100);
});

// SVG ring math for the trust-score donut.
const RING_RADIUS = 22;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - trustScore.value / 100));

const headerStats = computed(() => {
  const stats: { key: Bucket; count: number }[] = [
    { key: "valid", count: counts.value.valid },
    { key: "warning", count: counts.value.warning },
    { key: "halu", count: counts.value.halu },
  ];
  if (counts.value.pending > 0) stats.push({ key: "pending", count: counts.value.pending });
  return stats;
});

const filters: { key: "all" | Bucket; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "valid", label: "Valid" },
  { key: "warning", label: "Perlu Dicek" },
  { key: "halu", label: "Tidak Valid" },
  { key: "pending", label: "Belum Dicek" },
];

const activeFilter = ref<"all" | Bucket>("all");
const selectedRefId = ref<string | null>(null);
const expandedIds = reactive(new Set<string>());
const refItemEls = ref<Record<string, HTMLElement | null>>({});

const filteredReferences = computed(() =>
  activeFilter.value === "all"
    ? rows.value
    : rows.value.filter((row) => row.bucket === activeFilter.value),
);

function hexToRgba(hex: string, alpha: number) {
  const parsed = hex.replace("#", "");
  const r = parseInt(parsed.substring(0, 2), 16);
  const g = parseInt(parsed.substring(2, 4), 16);
  const b = parseInt(parsed.substring(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function toggleExpanded(id: string) {
  if (expandedIds.has(id)) expandedIds.delete(id);
  else expandedIds.add(id);
}

function scrollToRow(id: string) {
  requestAnimationFrame(() => {
    refItemEls.value[id]?.scrollIntoView({ behavior: "smooth", block: "center" });
  });
}

/** Klik baris referensi: sorot baris + lompat ke halaman PDF-nya. */
function selectReference(id: string) {
  selectedRefId.value = id;
  expandedIds.add(id);
  scrollToRow(id);
  viewer.value?.scrollToHighlight(id);
}

/** Klik sorotan di PDF: pilih referensi, atau referensi pasangan sebuah sitasi. */
function onViewerSelect(item: Pick<HighlightItem, "id" | "kind">) {
  if (item.kind === "reference") {
    selectedRefId.value = item.id;
    expandedIds.add(item.id);
    scrollToRow(item.id);
    return;
  }

  const citation = citations.value.find((entry) => entry.id === item.id);
  const referenceId = citation?.reference?.id ?? null;
  // Sitasi tanpa padanan tidak punya baris referensi yang bisa disorot.
  if (!referenceId) return;
  selectedRefId.value = referenceId;
  expandedIds.add(referenceId);
  scrollToRow(referenceId);
}

/* ---------- Unduh laporan ---------- */

const reportBusy = ref(false);
const reportError = ref("");

async function downloadReport() {
  if (!props.id || reportBusy.value) return;
  reportBusy.value = true;
  reportError.value = "";
  try {
    window.open(await reportUrl(props.id), "_blank");
  } catch (e) {
    reportError.value = e instanceof Error ? e.message : "Gagal menyiapkan laporan.";
  } finally {
    reportBusy.value = false;
  }
}

/* ---------- Status pemuatan ---------- */

const pdfSrc = computed(() => documentData.value?.file?.url ?? "");
const notCompleted = computed(
  () => documentData.value !== null && documentData.value.status !== "completed",
);
const errorMessage = computed(() => {
  if (!error.value) return "";
  return error.value instanceof ApiError ? error.value.detailMessages : error.value.message;
});

function goBack() {
  router.push({ name: "upload" });
}

function goToProcessing() {
  if (props.id) router.push({ name: "processing", params: { id: props.id } });
}

onMounted(() => {
  // Router masih memakai `:id?` (lihat `router/index.ts`); tanpa id tidak ada hasil.
  if (!props.id) {
    router.replace({ name: "upload" });
    return;
  }
  void load();
});
</script>

<template>
  <div>
    <div class="border-b" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <div class="max-w-7xl mx-auto px-6 py-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
          <button
            class="flex items-center gap-1 text-sm font-semibold"
            :style="{ color: 'var(--muted-foreground)' }"
            @click="goBack"
          >
            <span>&lsaquo;</span> Kembali
          </button>

          <span
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: 'var(--secondary)', color: 'var(--foreground)' }"
          >
            <span
              class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold"
              :style="{ background: 'var(--foreground)', color: 'var(--card)' }"
              >{{ counts.total }}</span
            >
            Total Referensi
          </span>

          <span
            v-for="stat in headerStats"
            :key="stat.key"
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: statusMeta[stat.key].bg, color: statusMeta[stat.key].fg }"
          >
            <span
              class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold"
              :style="{ background: 'white' }"
              >{{ stat.count }}</span
            >
            {{ statusMeta[stat.key].label }}
          </span>
        </div>

        <div class="flex items-center gap-4">
          <div class="flex items-center gap-3">
            <svg width="56" height="56" viewBox="0 0 56 56">
              <circle cx="28" cy="28" :r="RING_RADIUS" fill="none" stroke="var(--secondary)" stroke-width="5" />
              <circle
                cx="28"
                cy="28"
                :r="RING_RADIUS"
                fill="none"
                stroke="#d97706"
                stroke-width="5"
                stroke-linecap="round"
                :stroke-dasharray="RING_CIRCUMFERENCE"
                :stroke-dashoffset="ringOffset"
                transform="rotate(-90 28 28)"
              />
              <text x="28" y="32" text-anchor="middle" font-size="13" font-weight="800" fill="var(--foreground)">
                {{ trustScore }}%
              </text>
            </svg>
            <div>
              <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">Tingkat Kepercayaan</p>
              <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">
                {{ counts.valid }} dari {{ counts.total }} referensi valid
              </p>
            </div>
          </div>

          <button
            class="flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-full text-white flex-shrink-0 disabled:opacity-60"
            :style="{ background: 'var(--primary)' }"
            :disabled="reportBusy || notCompleted || !id"
            @click="downloadReport"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <path d="M12 3v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M3 15v4a2 2 0 002 2h14a2 2 0 002-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ reportBusy ? "Menyiapkan…" : "Unduh Laporan" }}
          </button>
        </div>
      </div>
    </div>

    <div class="result-workspace">
      <div v-if="error" class="max-w-7xl mx-auto px-6 pt-6">
        <div
          class="flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm"
          :style="{ background: statusMeta.halu.bg, color: statusMeta.halu.fg }"
        >
          <span>{{ errorMessage }}</span>
          <button class="font-bold underline" @click="load">Coba lagi</button>
        </div>
      </div>

      <div v-else-if="notCompleted" class="max-w-7xl mx-auto px-6 pt-6">
        <div
          class="flex flex-wrap items-center gap-3 rounded-xl px-4 py-3 text-sm"
          :style="{ background: statusMeta.pending.bg, color: statusMeta.pending.fg }"
        >
          <span>Analisis dokumen ini belum selesai, jadi hasilnya belum lengkap.</span>
          <button class="font-bold underline" @click="goToProcessing">Lihat progres</button>
        </div>
      </div>

      <div v-if="reportError" class="max-w-7xl mx-auto px-6 pt-4 text-sm" :style="{ color: statusMeta.halu.fg }">
        {{ reportError }}
      </div>

      <div class="result-layout max-w-7xl mx-auto px-6 py-8 grid grid-cols-1 lg:grid-cols-5 gap-8">
        <section class="lg:col-span-3">
          <div class="document-paper">
            <div class="document-toolbar">
              <div class="flex items-center gap-2 min-w-0">
                <span class="document-file-icon">PDF</span>
                <div class="min-w-0">
                  <p class="document-file-name truncate">{{ documentData?.name ?? "Dokumen" }}</p>
                  <p class="document-file-meta">Hasil pemeriksaan sitasi</p>
                </div>
              </div>
            </div>

            <PdfHighlightViewer
              v-if="pdfSrc"
              ref="viewer"
              :src="pdfSrc"
              :highlights="highlights"
              :selected-id="selectedRefId"
              class="rounded-none border-0 shadow-none"
              @select="onViewerSelect"
            />
            <div v-else class="document-viewer">
              <div class="document-viewer-empty">
                <p class="font-bold">{{ loading ? "Memuat dokumen…" : "Pratinjau PDF belum tersedia" }}</p>
                <p class="mt-1">File dokumen tidak dapat ditampilkan.</p>
              </div>
            </div>
          </div>
        </section>

      <div class="lg:col-span-2 reference-panel">
        <div class="flex items-center justify-between mb-4">
          <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">
            DAFTAR PUSTAKA
          </p>
          <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">{{ counts.total }} entri</p>
        </div>

        <div class="inline-flex p-1 rounded-full mb-4" :style="{ background: 'var(--secondary)' }">
          <button
            v-for="filter in filters"
            :key="filter.key"
            class="text-xs font-bold px-3.5 py-1.5 rounded-full transition-colors"
            :style="{
              background: activeFilter === filter.key ? 'var(--card)' : 'transparent',
              color: activeFilter === filter.key ? 'var(--foreground)' : 'var(--muted-foreground)',
              boxShadow: activeFilter === filter.key ? '0 1px 2px rgba(15,22,41,0.08)' : 'none',
            }"
            @click="activeFilter = filter.key"
          >
            {{ filter.label }}
          </button>
        </div>

        <div class="flex flex-col gap-3">
          <div
            v-for="reference in filteredReferences"
            :key="reference.id"
            :ref="(el) => (refItemEls[reference.id] = (el as any)?.$el ?? (el as any))"
            class="rounded-xl border-l-4 border border-t border-r border-b overflow-hidden cursor-pointer"
            :style="{
              borderLeftColor: statusMeta[reference.bucket].fg,
              borderTopColor: 'var(--border)',
              borderRightColor: 'var(--border)',
              borderBottomColor: 'var(--border)',
              background: 'var(--card)',
              outline: selectedRefId === reference.id ? `2px solid ${statusMeta[reference.bucket].fg}` : 'none',
              outlineOffset: '1px',
            }"
            @click="selectReference(reference.id)"
          >
            <div class="p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-2.5 min-w-0">
                  <span
                    class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-extrabold mt-0.5"
                    :style="{ background: hexToRgba(statusMeta[reference.bucket].fg, 0.18), color: statusMeta[reference.bucket].fg }"
                  >
                    {{ reference.index }}
                  </span>
                  <p class="text-sm font-bold leading-snug" :style="{ color: 'var(--foreground)' }">
                    {{ reference.title }}
                  </p>
                </div>

                <span
                  class="flex-shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full"
                  :style="{ background: statusMeta[reference.bucket].bg, color: statusMeta[reference.bucket].fg }"
                >
                  {{ statusMeta[reference.bucket].label }}
                </span>
              </div>

              <p v-if="reference.authors" class="text-xs mt-2 ml-8" :style="{ color: 'var(--muted-foreground)' }">
                {{ reference.authors }}
              </p>
              <p v-if="reference.year" class="text-xs ml-8" :style="{ color: 'var(--muted-foreground)' }">
                {{ reference.year }}
              </p>
              <a
                v-if="reference.doi"
                :href="`https://doi.org/${reference.doi}`"
                target="_blank"
                rel="noopener"
                class="text-xs mt-1 ml-8 inline-flex items-center gap-1 hover:underline"
                :style="{ color: 'var(--accent)' }"
                @click.stop
              >
                ↗ {{ reference.doi }}
              </a>
              <span v-else class="block text-xs mt-1 ml-8" :style="{ color: 'var(--muted-foreground)' }">
                Tanpa DOI di daftar pustaka
              </span>

              <button
                class="w-full mt-3 flex items-center justify-between text-xs font-semibold px-3 py-2 rounded-lg"
                :style="{ background: 'var(--secondary)', color: 'var(--foreground)' }"
                @click.stop="toggleExpanded(reference.id)"
              >
                <span class="flex items-center gap-1.5">ⓘ Lihat hasil validasi</span>
                <span :style="{ transform: expandedIds.has(reference.id) ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }">
                  ⌄
                </span>
              </button>

              <div
                v-if="expandedIds.has(reference.id)"
                class="mt-2 text-xs rounded-lg px-3 py-2.5"
                :style="{ background: 'var(--muted)', color: 'var(--foreground)' }"
              >
                <p class="font-semibold" :style="{ color: statusMeta[reference.bucket].fg }">
                  {{ statusMeta[reference.bucket].label }} &middot; status backend: {{ reference.findingStatus }}
                  <span v-if="reference.findingStatus !== 'pending'">
                    &middot; keyakinan {{ Math.round(reference.confidence * 100) }}%
                  </span>
                  <span v-if="reference.isManual">&middot; ditinjau manual</span>
                </p>
                <p v-if="reference.reason" class="mt-1">{{ reference.reason }}</p>
                <p v-else class="mt-1">Belum ada alasan dari backend.</p>

                <div
                  v-if="reference.candidate"
                  class="mt-2 pt-2"
                  :style="{ borderTop: '1px solid var(--border)' }"
                >
                  <p class="font-semibold">
                    Kandidat teratas &middot; {{ metadataSourceLabel(reference.candidate.source) }}
                  </p>
                  <p v-if="reference.candidate.title" class="mt-0.5">{{ reference.candidate.title }}</p>
                  <p v-if="reference.candidate.authors" class="mt-0.5">{{ reference.candidate.authors }}</p>
                  <p
                    v-if="reference.candidate.publication_name || reference.candidate.publication_year"
                    class="mt-0.5"
                  >
                    {{ reference.candidate.publication_name }}
                    <span v-if="reference.candidate.publication_year">({{ reference.candidate.publication_year }})</span>
                  </p>
                  <a
                    v-if="reference.candidate.doi"
                    :href="`https://doi.org/${reference.candidate.doi}`"
                    target="_blank"
                    rel="noopener"
                    class="mt-1 inline-flex items-center gap-1 hover:underline"
                    :style="{ color: 'var(--accent)' }"
                    @click.stop
                  >
                    ↗ {{ reference.candidate.doi }}
                  </a>
                  <p v-if="reference.candidate.match_reason" class="mt-1">
                    {{ reference.candidate.match_reason }}
                  </p>
                </div>

                <ul v-if="reference.citations.length" class="mt-2 flex flex-col gap-1">
                  <li v-for="citation in reference.citations" :key="citation.id">
                    &bull; {{ citation.citation_text }}
                    <span :style="{ color: 'var(--muted-foreground)' }">
                      ({{ citationStatusLabel[citation.status] ?? citation.status }})
                    </span>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <p v-if="filteredReferences.length === 0" class="text-sm text-center py-8" :style="{ color: 'var(--muted-foreground)' }">
            Tidak ada referensi pada kategori ini.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
</template>