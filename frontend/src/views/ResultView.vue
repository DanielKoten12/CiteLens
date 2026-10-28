<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import PdfHighlightViewer from "@/components/viewer/PdfHighlightViewer.vue";
import { useDocumentHighlights } from "@/composables/useDocumentHighlights";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import type { ApiCitation, ApiReference, Candidate, FindingStatus } from "@/types/api";
import type { HighlightItem } from "@/types/viewer";

const props = defineProps<{ id?: string }>();
const router = useRouter();

const viewer = ref<InstanceType<typeof PdfHighlightViewer> | null>(null);

const { document, references, citations, highlights, loading, error, load } =
  useDocumentHighlights(props.id);

onMounted(() => {
  if (!props.id) {
    router.replace({ name: "documents" });
    return;
  }
  void load();
});

// A document that is not finished belongs on the processing screen, not here.
watch(document, (doc) => {
  if (!doc) return;
  if (doc.status === "pending" || doc.status === "processing") {
    router.replace({ name: "processing", params: { id: doc.id } });
  }
});

const fileName = computed(
  () => document.value?.name ?? document.value?.file?.filename ?? "Dokumen",
);
const pdfUrl = computed(() => document.value?.file?.url ?? "");
const isProcessing = computed(
  () => document.value?.status === "pending" || document.value?.status === "processing",
);
const isFailed = computed(() => document.value?.status === "failed");
const failureText = computed(() => document.value?.error ?? "Analisis gagal. Silakan coba lagi.");

const loadError = computed(() => {
  if (!error.value) return "";
  if (error.value instanceof ApiError) {
    return error.value.status === 404 ? "Dokumen tidak ditemukan." : error.value.detailMessages;
  }
  return error.value.message;
});

/* ---------- Finding status presentation (canonical enums) ---------- */

const statusMeta: Record<
  FindingStatus,
  { label: string; bg: string; fg: string; color: string }
> = {
  valid: { label: "Valid", bg: "#dcfce7", fg: "#15803d", color: "#16a34a" },
  suspicious: { label: "Perlu Dicek", bg: "#fef3c7", fg: "#b45309", color: "#d97706" },
  invalid: { label: "Tidak Valid", bg: "#fee2e2", fg: "#b91c1c", color: "#dc2626" },
  not_found: { label: "Tidak Ditemukan", bg: "#fee2e2", fg: "#991b1b", color: "#b91c1c" },
  pending: { label: "Belum Dinilai", bg: "#e2e8f0", fg: "#475569", color: "#6b7a99" },
};

type FilterKey = "all" | FindingStatus;

const filters: { key: FilterKey; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "valid", label: "Valid" },
  { key: "suspicious", label: "Perlu Dicek" },
  { key: "invalid", label: "Tidak Valid" },
  { key: "not_found", label: "Tidak Ditemukan" },
];

const activeFilter = ref<FilterKey>("all");

function statusOf(reference: ApiReference): FindingStatus {
  return reference.finding?.status ?? "pending";
}

/**
 * Header counts come from the server-side summary (authoritative). Only fall
 * back to counting the loaded page when the summary is absent.
 */
const counts = computed(() => {
  const summary = document.value?.summary;
  if (summary) {
    return {
      total: summary.total_references,
      valid: summary.valid,
      suspicious: summary.suspicious,
      issues: summary.invalid + summary.not_found,
    };
  }
  const list = references.value;
  return {
    total: list.length,
    valid: list.filter((reference) => statusOf(reference) === "valid").length,
    suspicious: list.filter((reference) => statusOf(reference) === "suspicious").length,
    issues: list.filter((reference) => {
      const status = statusOf(reference);
      return status === "invalid" || status === "not_found";
    }).length,
  };
});

/**
 * UI-only heuristic, not an API field: a valid reference counts fully, a
 * suspicious one half. `total_references` is authoritative (pending references
 * have no bucket), so it stays the denominator.
 */
const trustScore = computed(() => {
  const { total, valid, suspicious } = counts.value;
  if (total === 0) return 0;
  return Math.round(((valid + suspicious * 0.5) / total) * 100);
});

const RING_RADIUS = 22;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - trustScore.value / 100));

const filteredReferences = computed(() => {
  if (activeFilter.value === "all") return references.value;
  return references.value.filter((reference) => statusOf(reference) === activeFilter.value);
});

const citationCount = computed(
  () => document.value?.summary?.total_citations ?? citations.value.length,
);

/* ---------- Selection, expansion, viewer sync ---------- */

const selectedRefId = ref<string | null>(null);
const expandedIds = reactive(new Set<string>());
const refItemEls = ref<Record<string, HTMLElement | null>>({});

// Full reference detail (finding candidates, manual-review fields) is fetched
// only when a card is expanded, never at page load.
const referenceDetails = ref<Record<string, ApiReference>>({});
const detailErrors = reactive(new Set<string>());

async function loadReferenceDetail(id: string) {
  if (referenceDetails.value[id] || detailErrors.has(id)) return;
  try {
    const { data } = await documentsApi.reference(id);
    referenceDetails.value[id] = data;
  } catch {
    detailErrors.add(id);
  }
}

function findingFor(reference: ApiReference) {
  return referenceDetails.value[reference.id]?.finding ?? reference.finding;
}

const citationsByReference = computed(() => {
  const byReference = new Map<string, ApiCitation[]>();
  for (const citation of citations.value) {
    if (!citation.reference) continue;
    const list = byReference.get(citation.reference.id) ?? [];
    list.push(citation);
    byReference.set(citation.reference.id, list);
  }
  return byReference;
});

function referenceCitations(reference: ApiReference) {
  return citationsByReference.value.get(reference.id) ?? [];
}

function setRefItem(id: string, element: unknown) {
  const instance = element as { $el?: unknown } | null;
  const el = instance && typeof instance === "object" && "$el" in instance ? instance.$el : element;
  refItemEls.value[id] = el instanceof HTMLElement ? el : null;
}

function focusReference(id: string) {
  selectedRefId.value = id;
  activeFilter.value = "all";
  expandedIds.add(id);
  void loadReferenceDetail(id);
  void nextTick(() => {
    refItemEls.value[id]?.scrollIntoView({ behavior: "smooth", block: "center" });
  });
}

function selectReference(reference: ApiReference) {
  focusReference(reference.id);
  viewer.value?.scrollToHighlight(reference.id);
}

function onViewerSelect(item: Pick<HighlightItem, "id" | "kind">) {
  if (item.kind === "reference") {
    focusReference(item.id);
    return;
  }
  const citation = citations.value.find((entry) => entry.id === item.id);
  if (citation?.reference) focusReference(citation.reference.id);
}

function toggleExpanded(id: string) {
  if (expandedIds.has(id)) {
    expandedIds.delete(id);
    return;
  }
  expandedIds.add(id);
  void loadReferenceDetail(id);
}

/* ---------- Reference presentation ---------- */

function referenceTitle(reference: ApiReference) {
  return reference.title?.trim() || reference.raw_text?.trim() || "Tanpa judul";
}

function doiUrl(doi: string | null) {
  if (!doi) return null;
  const cleaned = doi.trim().replace(/^https?:\/\/(dx\.)?doi\.org\//i, "");
  return cleaned ? `https://doi.org/${cleaned}` : null;
}

function confidencePercent(reference: ApiReference) {
  const confidence = findingFor(reference)?.confidence;
  return confidence == null ? null : Math.round(confidence * 100);
}

function candidatesFor(reference: ApiReference): Candidate[] {
  return findingFor(reference)?.candidates ?? [];
}

function selectedCandidate(reference: ApiReference): Candidate | null {
  const candidates = candidatesFor(reference);
  if (candidates.length === 0) return null;
  const selectedId = findingFor(reference)?.selected_candidate_id;
  return candidates.find((candidate) => candidate.id === selectedId) ?? candidates[0];
}

function hexToRgba(hex: string, alpha: number) {
  const parsed = hex.replace("#", "");
  const r = parseInt(parsed.substring(0, 2), 16);
  const g = parseInt(parsed.substring(2, 4), 16);
  const b = parseInt(parsed.substring(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/* ---------- Report download ---------- */

const reportState = ref<"idle" | "generating" | "error">("idle");
const reportError = ref("");

function wait(milliseconds: number) {
  return new Promise<void>((resolve) => window.setTimeout(resolve, milliseconds));
}

async function downloadReport() {
  const doc = document.value;
  if (!doc || doc.status !== "completed" || reportState.value === "generating") return;

  reportState.value = "generating";
  reportError.value = "";

  // Opened during the click gesture: popup blockers reject window.open()
  // once generation has awaited for a while.
  const reportTab = window.open("", "_blank");
  if (reportTab) reportTab.opener = null;

  try {
    const created = await documentsApi.createReport(doc.id);
    const reportId = created.data.id;

    for (let attempt = 0; attempt < 30; attempt += 1) {
      try {
        const current = attempt === 0 ? created.data : (await documentsApi.report(reportId)).data;
        if (current.status === "completed" && current.download_url) {
          if (reportTab) reportTab.location.href = current.download_url;
          else window.location.assign(current.download_url);
          reportState.value = "idle";
          return;
        }
        if (current.status === "failed") {
          throw new Error(current.error ?? "Laporan gagal dibuat.");
        }
      } catch (caught) {
        if (!(caught instanceof ApiError) || caught.status !== 429) throw caught;
      }
      await wait(2000);
    }

    throw new Error("Laporan belum selesai dibuat. Coba unduh lagi nanti.");
  } catch (caught) {
    reportTab?.close();
    reportError.value =
      caught instanceof ApiError
        ? caught.status === 409
          ? "Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis."
          : caught.detailMessages
        : caught instanceof Error
          ? caught.message
          : "Gagal membuat laporan.";
    reportState.value = "error";
  }
}

function goBack() {
  router.push({ name: "upload" });
}
</script>

<template>
  <div v-if="loading" class="max-w-7xl mx-auto px-6 py-20 text-center text-sm" :style="{ color: 'var(--muted-foreground)' }">
    Memuat hasil pemeriksaan&hellip;
  </div>

  <div v-else-if="loadError" class="max-w-lg mx-auto px-6 py-20 text-center">
    <p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">Hasil tidak dapat dimuat</p>
    <p class="text-sm mt-2" :style="{ color: 'var(--muted-foreground)' }">{{ loadError }}</p>
    <button
      class="mt-6 text-sm font-bold px-5 py-2.5 rounded-full text-white"
      :style="{ background: 'var(--primary)' }"
      @click="goBack"
    >
      Kembali
    </button>
  </div>

  <div v-else-if="isProcessing" class="max-w-7xl mx-auto px-6 py-20 text-center text-sm" :style="{ color: 'var(--muted-foreground)' }">
    Analisis masih berjalan&hellip;
  </div>

  <div v-else-if="isFailed" class="max-w-lg mx-auto px-6 py-20 text-center">
    <p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">Analisis Gagal</p>
    <p class="text-sm mt-2" style="color: #b91c1c">{{ failureText }}</p>
    <div class="flex items-center justify-center gap-2 mt-6">
      <button
        class="text-sm font-bold px-4 py-2 rounded-lg text-white"
        :style="{ background: 'var(--accent)' }"
        @click="router.push({ name: 'processing', params: { id: document?.id } })"
      >
        Buka halaman proses
      </button>
      <button
        class="text-sm font-bold px-4 py-2 rounded-lg border"
        :style="{ borderColor: 'var(--border)', color: 'var(--foreground)' }"
        @click="goBack"
      >
        Unggah file lain
      </button>
    </div>
  </div>

  <div v-else-if="document">
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
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: statusMeta.valid.bg, color: statusMeta.valid.fg }"
          >
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{
              counts.valid
            }}</span>
            Valid
          </span>

          <span
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: statusMeta.suspicious.bg, color: statusMeta.suspicious.fg }"
          >
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{
              counts.suspicious
            }}</span>
            Perlu Dicek
          </span>

          <span
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: statusMeta.invalid.bg, color: statusMeta.invalid.fg }"
          >
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{
              counts.issues
            }}</span>
            Bermasalah
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
              <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">{{ trustScore }}% referensi valid</p>
            </div>
          </div>

          <button
            class="flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-full text-white flex-shrink-0 disabled:opacity-60"
            :style="{ background: 'var(--primary)' }"
            :disabled="reportState === 'generating'"
            @click="downloadReport"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <path d="M12 3v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M3 15v4a2 2 0 002 2h14a2 2 0 002-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ reportState === "generating" ? "Menyiapkan laporan..." : "Unduh Laporan" }}
          </button>
        </div>
      </div>

      <p v-if="reportError" class="max-w-7xl mx-auto px-6 pb-3 text-xs" style="color: #b91c1c">{{ reportError }}</p>
    </div>

    <div class="result-workspace">
      <div class="result-layout max-w-7xl mx-auto px-6 py-8 grid grid-cols-1 lg:grid-cols-5 gap-8">
        <section class="lg:col-span-3">
          <div class="document-paper">
            <div class="document-toolbar">
              <div class="flex items-center gap-2 min-w-0">
                <span class="document-file-icon">PDF</span>
                <div class="min-w-0">
                  <p class="document-file-name truncate">{{ fileName }}</p>
                  <p class="document-file-meta">Hasil pemeriksaan sitasi</p>
                </div>
              </div>
              <span class="document-page-label">{{ counts.total }} referensi &middot; {{ citationCount }} sitasi</span>
            </div>

            <div class="p-3">
              <PdfHighlightViewer
                v-if="pdfUrl"
                ref="viewer"
                :src="pdfUrl"
                :highlights="highlights"
                :selected-id="selectedRefId"
                @select="onViewerSelect"
              />
              <div v-else class="document-viewer-empty">
                <p class="font-bold">Pratinjau PDF belum tersedia</p>
                <p class="mt-1">Berkas dokumen tidak dapat dimuat.</p>
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

          <div class="inline-flex flex-wrap p-1 rounded-full mb-4" :style="{ background: 'var(--secondary)' }">
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
              v-for="(reference, index) in filteredReferences"
              :key="reference.id"
              :ref="(el) => setRefItem(reference.id, el)"
              class="rounded-xl border-l-4 border border-t border-r border-b overflow-hidden"
              :style="{
                borderLeftColor: statusMeta[statusOf(reference)].color,
                borderTopColor: 'var(--border)',
                borderRightColor: 'var(--border)',
                borderBottomColor: 'var(--border)',
                background: 'var(--card)',
                outline: selectedRefId === reference.id ? `2px solid ${statusMeta[statusOf(reference)].color}` : 'none',
                outlineOffset: '1px',
              }"
            >
              <div class="p-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex items-start gap-2.5 min-w-0">
                    <span
                      class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-extrabold mt-0.5"
                      :style="{
                        background: hexToRgba(statusMeta[statusOf(reference)].color, 0.18),
                        color: statusMeta[statusOf(reference)].color,
                      }"
                    >
                      {{ index + 1 }}
                    </span>
                    <button
                      class="text-sm font-bold leading-snug text-left"
                      :style="{ color: 'var(--foreground)' }"
                      @click="selectReference(reference)"
                    >
                      {{ referenceTitle(reference) }}
                    </button>
                  </div>

                  <span
                    class="flex-shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full"
                    :style="{
                      background: statusMeta[statusOf(reference)].bg,
                      color: statusMeta[statusOf(reference)].fg,
                    }"
                  >
                    {{ statusMeta[statusOf(reference)].label }}
                  </span>
                </div>

                <p class="text-xs mt-2 ml-8" :style="{ color: 'var(--muted-foreground)' }">
                  {{ reference.authors || "Penulis tidak tercantum" }}
                </p>
                <p class="text-xs ml-8" :style="{ color: 'var(--muted-foreground)' }">
                  {{ reference.publication_year ?? "Tahun tidak tercantum" }}
                  <template v-if="reference.publication_name"> &middot; {{ reference.publication_name }}</template>
                </p>
                <a
                  v-if="doiUrl(reference.doi)"
                  :href="doiUrl(reference.doi)!"
                  target="_blank"
                  rel="noopener"
                  class="text-xs mt-1 ml-8 inline-flex items-center gap-1 hover:underline"
                  :style="{ color: 'var(--accent)' }"
                  @click.stop
                >
                  ↗ {{ reference.doi }}
                </a>

                <button
                  class="w-full mt-3 flex items-center justify-between text-xs font-semibold px-3 py-2 rounded-lg"
                  :style="{ background: 'var(--secondary)', color: 'var(--foreground)' }"
                  @click="toggleExpanded(reference.id)"
                >
                  <span class="flex items-center gap-1.5">ⓘ Lihat hasil cek DOI</span>
                  <span :style="{ transform: expandedIds.has(reference.id) ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }">
                    ⌄
                  </span>
                </button>

                <div
                  v-if="expandedIds.has(reference.id)"
                  class="mt-2 text-xs rounded-lg px-3 py-2.5"
                  :style="{ background: 'var(--muted)', color: 'var(--foreground)' }"
                >
                  <p class="font-bold">
                    {{ statusMeta[statusOf(reference)].label }}
                    <template v-if="confidencePercent(reference) !== null">
                      &middot; keyakinan {{ confidencePercent(reference) }}%
                    </template>
                    <template v-if="findingFor(reference)?.is_manual"> &middot; diverifikasi manual</template>
                  </p>
                  <p class="mt-1">
                    {{ findingFor(reference)?.reason || "Belum ada alasan dari sistem." }}
                  </p>

                  <div
                    v-if="selectedCandidate(reference)"
                    class="mt-2 rounded-lg px-3 py-2"
                    :style="{ background: 'var(--card)' }"
                  >
                    <p class="font-bold">Kandidat terpilih (Crossref)</p>
                    <p class="mt-1">{{ selectedCandidate(reference)!.title }}</p>
                    <p :style="{ color: 'var(--muted-foreground)' }">
                      {{ selectedCandidate(reference)!.publication_name }}
                      <template v-if="selectedCandidate(reference)!.publication_year">
                        &middot; {{ selectedCandidate(reference)!.publication_year }}
                      </template>
                    </p>
                    <p v-if="selectedCandidate(reference)!.match_reason" class="mt-1" :style="{ color: 'var(--muted-foreground)' }">
                      {{ selectedCandidate(reference)!.match_reason }}
                    </p>
                    <a
                      v-if="selectedCandidate(reference)!.url"
                      :href="selectedCandidate(reference)!.url"
                      target="_blank"
                      rel="noopener"
                      class="mt-1 inline-block hover:underline"
                      :style="{ color: 'var(--accent)' }"
                    >
                      ↗ {{ selectedCandidate(reference)!.doi || selectedCandidate(reference)!.url }}
                    </a>
                  </div>
                  <p v-else-if="referenceDetails[reference.id]" class="mt-2" :style="{ color: 'var(--muted-foreground)' }">
                    Tidak ada kandidat Crossref untuk referensi ini.
                  </p>
                  <p v-else-if="detailErrors.has(reference.id)" class="mt-2" :style="{ color: 'var(--muted-foreground)' }">
                    Detail kandidat tidak tersedia.
                  </p>
                  <p v-else class="mt-2" :style="{ color: 'var(--muted-foreground)' }">
                    Memuat kandidat Crossref&hellip;
                  </p>

                  <ul v-if="referenceCitations(reference).length" class="mt-2 flex flex-col gap-1">
                    <li v-for="citation in referenceCitations(reference)" :key="citation.id">
                      &bull; Disitasi sebagai {{ citation.citation_text }}
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
