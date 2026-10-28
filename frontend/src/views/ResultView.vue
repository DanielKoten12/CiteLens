<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ApiError } from "@/lib/api";
import { useDocumentHighlights } from "@/composables/useDocumentHighlights";
import PdfHighlightViewer from "@/components/viewer/PdfHighlightViewer.vue";
import { citationGroup, countByStatus, toReferenceRow, type ReferenceRow, type UiStatus } from "@/lib/resultAdapters";

const props = defineProps<{ id?: string }>();
const route = useRoute();
const router = useRouter();
const mockMode = import.meta.env.DEV && route.query.mock === "1";
const viewerRef = ref<InstanceType<typeof PdfHighlightViewer> | null>(null);
const selectedRefId = ref<string | null>(null);
const selectedHighlightId = ref<string | null>(null);
const activeFilter = ref<"all" | UiStatus>("all");
const expandedIds = ref(new Set<string>());
const refItemEls = ref<Record<string, HTMLElement | null>>({});
const citationItemEls = ref<Record<string, HTMLElement | null>>({});
const activeOccurrenceByReference = ref<Record<string, number>>({});

const { document, references, citations, highlights, loading, error, load } = useDocumentHighlights(
  props.id,
  { mock: mockMode },
);

onMounted(() => void load());

watch(
  () => document.value?.status,
  (status) => {
    if (!mockMode && props.id && (status === "pending" || status === "processing")) {
      void router.replace({ name: "processing", params: { id: props.id } });
    }
  },
  { immediate: true },
);

const rows = computed(() => references.value.map(toReferenceRow));
const counts = computed(() => ({ total: rows.value.length, ...countByStatus(rows.value) }));
const filteredReferences = computed(() =>
  activeFilter.value === "all" ? rows.value : rows.value.filter((row) => row.status === activeFilter.value),
);
const citationsForReference = (referenceId: string) =>
  citations.value
    .filter((citation) => citation.reference?.id === referenceId)
    .sort((left, right) => (left.occurrence_index ?? 0) - (right.occurrence_index ?? 0));
const orphanCitations = computed(() => citations.value.filter((citation) => citation.reference === null));
const visibleOrphanCitations = computed(() =>
  activeFilter.value === "all"
    ? orphanCitations.value
    : orphanCitations.value.filter((citation) => citationGroup(citation.status) === activeFilter.value),
);
const visibleHighlights = computed(() => {
  if (activeFilter.value === "all") return highlights.value;
  return highlights.value.filter((highlight) => {
    if (highlight.kind === "reference") {
      const reference = references.value.find((item) => item.id === highlight.id);
      if (!reference) return false;
      return toReferenceRow(reference, references.value.indexOf(reference)).status === activeFilter.value;
    }
    const citation = citations.value.find((item) => item.id === highlight.id);
    return citation ? citationGroup(citation.status) === activeFilter.value : false;
  });
});

const fileName = computed(() => document.value?.file?.filename ?? document.value?.name ?? "Dokumen PDF");
const pdfUrl = computed(() => (mockMode ? "/sample.pdf" : document.value?.file?.url ?? ""));
const errorMessage = computed(() => {
  if (error.value instanceof ApiError) return error.value.detailMessages;
  return error.value?.message ?? "Gagal memuat hasil pemeriksaan.";
});
const isNotFound = computed(() => !mockMode && !props.id);
const isFailed = computed(() => !mockMode && document.value?.status === "failed");
const trustScore = computed(() => {
  if (!counts.value.total) return 0;
  return Math.round(((counts.value.valid + counts.value.warning * 0.5) / counts.value.total) * 100);
});
const RING_RADIUS = 22;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - trustScore.value / 100));
const filters = [
  { key: "all" as const, label: "Semua" },
  { key: "valid" as const, label: "Valid" },
  { key: "warning" as const, label: "Perlu Dicek" },
  { key: "halu" as const, label: "Halu" },
];
const statusMeta: Record<UiStatus, { label: string; fg: string; bg: string }> = {
  valid: { label: "Valid", fg: "#15803d", bg: "#dcfce7" },
  warning: { label: "Perlu Dicek", fg: "#b45309", bg: "#fef3c7" },
  halu: { label: "Halu", fg: "#b91c1c", bg: "#fee2e2" },
};
const citationMeta: Record<UiStatus, { label: string; fg: string; bg: string }> = {
  valid: { label: "Valid", fg: "#15803d", bg: "#dcfce7" },
  warning: { label: "Perlu Dicek", fg: "#b45309", bg: "#fef3c7" },
  halu: { label: "Sitasi bermasalah", fg: "#a16207", bg: "#fef9c3" },
};

function selectReference(id: string) {
  selectedRefId.value = id;
  selectedHighlightId.value = id;
  activeFilter.value = "all";
  expandedIds.value.add(id);
  void nextTick(() => refItemEls.value[id]?.scrollIntoView({ behavior: "smooth", block: "center" }));
  viewerRef.value?.scrollToHighlight(id);
}

function scrollCitationCard(id: string) {
  void nextTick(() => citationItemEls.value[id]?.scrollIntoView({ behavior: "smooth", block: "center" }));
}

function selectCitation(citationId: string) {
  const citation = citations.value.find((item) => item.id === citationId);
  if (!citation) return;

  selectedHighlightId.value = citation.id;
  selectedRefId.value = citation.reference?.id ?? null;
  activeFilter.value = "all";
  if (citation.reference?.id) {
    const matches = citationsForReference(citation.reference.id);
    const occurrence = matches.findIndex((match) => match.id === citation.id);
    activeOccurrenceByReference.value = {
      ...activeOccurrenceByReference.value,
      [citation.reference.id]: Math.max(0, occurrence),
    };
    expandedIds.value.add(citation.reference.id);
  }
  scrollCitationCard(citation.id);
  viewerRef.value?.scrollToHighlight(citation.id);
}

function navigateCitation(referenceId: string, delta: number) {
  const matches = citationsForReference(referenceId);
  if (matches.length === 0) return;
  const current = activeOccurrenceByReference.value[referenceId] ?? 0;
  const next = (current + delta + matches.length) % matches.length;
  activeOccurrenceByReference.value = { ...activeOccurrenceByReference.value, [referenceId]: next };
  selectCitation(matches[next].id);
}

function onSelectHighlight(selection: { id: string; kind: "reference" | "citation" }) {
  selectedHighlightId.value = selection.id;
  activeFilter.value = "all";
  if (selection.kind === "citation") {
    selectCitation(selection.id);
    return;
  }
  const referenceId =
    selection.kind === "reference" ? selection.id : null;
  if (!referenceId) return;
  selectedRefId.value = referenceId;
  expandedIds.value.add(referenceId);
  void nextTick(() => refItemEls.value[referenceId]?.scrollIntoView({ behavior: "smooth", block: "center" }));
}

function toggleExpanded(id: string) {
  const next = new Set(expandedIds.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  expandedIds.value = next;
}

function goBack() {
  void router.push({ name: "upload" });
}

function downloadReport() {
  // TODO: sambungkan ke endpoint export PDF laporan hasil.
}
</script>

<template>
  <div class="result-page">
    <div v-if="!isNotFound" class="result-summary border-b" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <div class="max-w-7xl mx-auto px-6 py-4 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
          <button class="flex items-center gap-1 text-sm font-semibold" :style="{ color: 'var(--muted-foreground)' }" @click="goBack">
            <span>&lsaquo;</span> Kembali
          </button>
          <span v-for="chip in [
            { label: 'Total Referensi', value: counts.total, style: { background: 'var(--secondary)', color: 'var(--foreground)' } },
            { label: 'Valid', value: counts.valid, style: statusMeta.valid },
            { label: 'Perlu Dicek', value: counts.warning, style: statusMeta.warning },
            { label: 'Halu / Tidak Ditemukan', value: counts.halu, style: statusMeta.halu },
          ]" :key="chip.label" class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full" :style="chip.style">
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{ chip.value }}</span>
            {{ chip.label }}
          </span>
        </div>
        <div class="flex items-center gap-4">
          <div class="flex items-center gap-3">
            <svg width="56" height="56" viewBox="0 0 56 56">
              <circle cx="28" cy="28" :r="RING_RADIUS" fill="none" stroke="var(--secondary)" stroke-width="5" />
              <circle cx="28" cy="28" :r="RING_RADIUS" fill="none" stroke="#d97706" stroke-width="5" stroke-linecap="round" :stroke-dasharray="RING_CIRCUMFERENCE" :stroke-dashoffset="ringOffset" transform="rotate(-90 28 28)" />
              <text x="28" y="32" text-anchor="middle" font-size="13" font-weight="800" fill="var(--foreground)">{{ trustScore }}%</text>
            </svg>
            <div>
              <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">Tingkat Kepercayaan</p>
              <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">{{ trustScore }}% referensi valid</p>
            </div>
          </div>
          <button class="flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-full text-white flex-shrink-0" :style="{ background: 'var(--primary)' }" @click="downloadReport">
            Unduh Laporan
          </button>
        </div>
      </div>
    </div>

    <div v-if="isNotFound" class="max-w-2xl mx-auto px-6 py-16 text-center">
      <h1 class="text-xl font-bold">Dokumen tidak ditemukan</h1>
      <button class="mt-5 rounded-xl px-4 py-2 text-sm font-bold text-white" :style="{ background: 'var(--primary)' }" @click="goBack">Kembali</button>
    </div>
    <div v-else-if="loading" class="max-w-2xl mx-auto px-6 py-16 text-center">Memuat hasil pemeriksaan&hellip;</div>
    <div v-else-if="isFailed || error" class="max-w-2xl mx-auto px-6 py-16 text-center">
      <h1 class="text-xl font-bold">{{ isFailed ? "Pemeriksaan gagal" : "Hasil tidak dapat dimuat" }}</h1>
      <p class="mt-2 text-sm" :style="{ color: 'var(--muted-foreground)' }">{{ isFailed ? document?.error : errorMessage }}</p>
      <button class="mt-5 rounded-xl px-4 py-2 text-sm font-bold text-white" :style="{ background: 'var(--primary)' }" @click="goBack">Unggah file lain</button>
    </div>
    <div v-else class="result-workspace">
      <div class="result-layout max-w-7xl mx-auto px-6 py-4 grid grid-cols-1 lg:grid-cols-5 gap-6 min-w-0">
        <section class="lg:col-span-3 min-w-0 min-h-0 h-full">
          <div class="document-paper h-full flex min-h-0 flex-col">
            <div class="document-toolbar">
              <div class="flex items-center gap-2 min-w-0">
                <span class="document-file-icon">PDF</span>
                <div class="min-w-0">
                  <p class="document-file-name truncate">{{ fileName }}</p>
                  <p class="document-file-meta">Hasil pemeriksaan sitasi</p>
                </div>
              </div>
            </div>
            <div class="document-viewer min-w-0 min-h-0 flex-1">
              <PdfHighlightViewer ref="viewerRef" :src="pdfUrl" :highlights="visibleHighlights" :selected-id="selectedHighlightId" @select="onSelectHighlight" />
            </div>
          </div>
        </section>

        <div class="lg:col-span-2 reference-panel min-w-0 min-h-0 overflow-y-auto pr-1">
          <div class="flex items-center justify-between mb-4">
            <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">DAFTAR PUSTAKA</p>
            <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">{{ counts.total }} entri</p>
          </div>
          <div class="inline-flex p-1 rounded-full mb-4" :style="{ background: 'var(--secondary)' }">
            <button v-for="filter in filters" :key="filter.key" class="text-xs font-bold px-3.5 py-1.5 rounded-full transition-colors" :style="{ background: activeFilter === filter.key ? 'var(--card)' : 'transparent', color: activeFilter === filter.key ? 'var(--foreground)' : 'var(--muted-foreground)' }" @click="activeFilter = filter.key">
              {{ filter.label }}
            </button>
          </div>
          <div class="flex flex-col gap-3">
            <div v-for="reference in filteredReferences" :key="reference.id" :ref="(el) => (refItemEls[reference.id] = (el as HTMLElement | null))" class="rounded-xl border-l-4 border overflow-hidden" :style="{ borderLeftColor: statusMeta[reference.status].fg, borderColor: 'var(--border)', background: 'var(--card)', outline: selectedRefId === reference.id ? `2px solid ${statusMeta[reference.status].fg}` : 'none', outlineOffset: '1px' }">
              <div class="p-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex items-start gap-2.5 min-w-0">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-extrabold mt-0.5" :style="{ background: statusMeta[reference.status].bg, color: statusMeta[reference.status].fg }">{{ reference.no }}</span>
                    <p class="text-sm font-bold leading-snug cursor-pointer" :style="{ color: 'var(--foreground)' }" @click="selectReference(reference.id)">{{ reference.title }}</p>
                  </div>
                  <span class="flex-shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full" :style="{ background: statusMeta[reference.status].bg, color: statusMeta[reference.status].fg }">{{ statusMeta[reference.status].label }}</span>
                </div>
                <p class="text-xs mt-2 ml-8" :style="{ color: 'var(--muted-foreground)' }">{{ reference.authors }}</p>
                <p v-if="reference.year" class="text-xs ml-8" :style="{ color: 'var(--muted-foreground)' }">{{ reference.year }}</p>
                <a v-if="reference.doi" :href="`https://doi.org/${reference.doi}`" target="_blank" rel="noopener" class="text-xs mt-1 ml-8 inline-flex hover:underline" :style="{ color: 'var(--accent)' }" @click.stop>↗ {{ reference.doi }}</a>
                <p v-else class="text-xs mt-1 ml-8" :style="{ color: 'var(--muted-foreground)' }">Tidak ada DOI</p>
                <div class="mt-3 rounded-lg px-3 py-2" :style="{ background: 'var(--secondary)' }">
                  <template v-if="citationsForReference(reference.id).length">
                    <div class="flex items-center justify-between text-xs font-semibold">
                      <button class="rounded px-1.5 py-0.5 disabled:opacity-40" :disabled="citationsForReference(reference.id).length < 2" @click.stop="navigateCitation(reference.id, -1)">&lsaquo;</button>
                      <span>{{ (activeOccurrenceByReference[reference.id] ?? 0) + 1 }} of {{ citationsForReference(reference.id).length }}</span>
                      <button class="rounded px-1.5 py-0.5 disabled:opacity-40" :disabled="citationsForReference(reference.id).length < 2" @click.stop="navigateCitation(reference.id, 1)">&rsaquo;</button>
                    </div>
                    <div v-for="(citation, citationIndex) in citationsForReference(reference.id)" v-show="citationIndex === (activeOccurrenceByReference[reference.id] ?? 0)" :key="citation.id" class="mt-2 rounded px-1 text-xs leading-relaxed cursor-pointer" :style="{ color: 'var(--muted-foreground)', background: citationMeta[citationGroup(citation.status)].bg }" @click.stop="selectCitation(citation.id)">
                      <span>{{ citation.context_before }}</span>
                      <strong :style="{ color: citationMeta[citationGroup(citation.status)].fg }">{{ citation.citation_text }}</strong>
                      <span>{{ citation.context_after }}</span>
                    </div>
                  </template>
                  <span v-else class="text-xs" :style="{ color: 'var(--muted-foreground)' }">Tidak disitasi di naskah</span>
                </div>
                <button class="w-full mt-3 flex items-center justify-between text-xs font-semibold px-3 py-2 rounded-lg" :style="{ background: 'var(--secondary)', color: 'var(--foreground)' }" @click.stop="toggleExpanded(reference.id)">
                  <span>ⓘ Lihat hasil cek DOI</span><span>{{ expandedIds.has(reference.id) ? "⌃" : "⌄" }}</span>
                </button>
                <div v-if="expandedIds.has(reference.id)" class="mt-2 text-xs rounded-lg px-3 py-2.5" :style="{ background: 'var(--muted)', color: 'var(--foreground)' }">
                  <p v-if="reference.confidence != null">Kemiripan: {{ reference.confidence }}%</p>
                  <p>{{ reference.reason }}</p>
                </div>
              </div>
            </div>
            <div v-for="citation in visibleOrphanCitations" :key="citation.id" :ref="(el) => (citationItemEls[citation.id] = (el as HTMLElement | null))" class="rounded-xl border-l-4 border overflow-hidden cursor-pointer" :style="{ borderLeftColor: citationMeta[citationGroup(citation.status)].fg, borderColor: 'var(--border)', background: 'var(--card)', outline: selectedHighlightId === citation.id ? `2px solid ${citationMeta[citationGroup(citation.status)].fg}` : 'none', outlineOffset: '1px' }" @click="selectCitation(citation.id)">
              <div class="p-4">
                <div class="flex items-start justify-between gap-3">
                  <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">Sitasi tanpa entri di daftar pustaka</p>
                  <span class="flex-shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full" :style="{ background: citationMeta[citationGroup(citation.status)].bg, color: citationMeta[citationGroup(citation.status)].fg }">Sitasi bermasalah</span>
                </div>
                <p class="text-xs mt-2 leading-relaxed" :style="{ color: 'var(--muted-foreground)' }">
                  <span>{{ citation.context_before }}</span>
                  <strong :style="{ color: 'var(--foreground)' }">{{ citation.citation_text }}</strong>
                  <span>{{ citation.context_after }}</span>
                </p>
                <div class="mt-3 flex items-center justify-between text-xs font-semibold rounded-lg px-3 py-2" :style="{ background: 'var(--secondary)' }">
                  <button class="rounded px-1.5 py-0.5 disabled:opacity-40" disabled>&lsaquo;</button>
                  <span>1 of 1</span>
                  <button class="rounded px-1.5 py-0.5 disabled:opacity-40" disabled>&rsaquo;</button>
                </div>
              </div>
            </div>
            <p v-if="filteredReferences.length === 0" class="text-sm text-center py-8" :style="{ color: 'var(--muted-foreground)' }">Tidak ada referensi pada kategori ini.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
