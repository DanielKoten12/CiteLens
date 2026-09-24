<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import type { DocumentSegment, FilterStatus, Reference, RefStatus } from "@/types";

const router = useRouter();

// TODO: ganti mock data ini dengan hasil asli dari backend / store setelah
// pipeline pemeriksaan selesai (lihat ProcessingView.vue).
const fileName = "naskah-skripsi-bab-2.pdf";

const references: Reference[] = [
  {
    id: 1,
    color: "#d97706",
    title: "Deep Learning for Natural Language Processing: A Comprehensive Survey",
    authors: "LeCun, Y., Bengio, Y., & Hinton, G.",
    year: 2019,
    doi: "10.1145/3234435.3234512",
    status: "valid",
    citedAt: [0],
    citedInText: true,
    doiCheck: { status: "resolves_match", resolvedTitle: "Deep Learning for Natural Language Processing: A Comprehensive Survey", titleSimilarity: 98 },
  },
  {
    id: 2,
    color: "#16a34a",
    title: "Transformer Architecture and Attention Mechanisms in Modern NLP",
    authors: "Vaswani, A., Shazeer, N., Parmar, N., & Uszkoreit, J.",
    year: 2017,
    doi: "10.5555/3295222.3295349",
    status: "valid",
    citedAt: [1],
    citedInText: true,
    doiCheck: { status: "resolves_match", resolvedTitle: "Attention Is All You Need", titleSimilarity: 95 },
  },
  {
    id: 3,
    color: "#7c3aed",
    title: "BERT: Pre-training of Deep Bidirectional Transformers for Language Understanding",
    authors: "Devlin, J., Chang, M. W., Lee, K., & Toutanova, K.",
    year: 2019,
    doi: "10.18653/v1/N19-1423",
    status: "valid",
    citedAt: [2],
    citedInText: true,
    doiCheck: { status: "resolves_match", resolvedTitle: "BERT: Pre-training of Deep Bidirectional Transformers for Language Understanding", titleSimilarity: 100 },
  },
  {
    id: 4,
    color: "#2563eb",
    title: "Kualitas Sitasi dalam Karya Ilmiah Mahasiswa Indonesia",
    authors: "Sutrisno, B., & Rahardjo, D.",
    year: 2020,
    doi: "10.21831/jpai.v8i2.3311",
    status: "warning",
    citedAt: [3],
    citedInText: true,
    warningReasons: [
      "Judul pada DOI sedikit berbeda dari yang tertulis di daftar pustaka.",
      "Nama jurnal tidak sepenuhnya cocok dengan hasil pencarian Crossref.",
    ],
    doiCheck: { status: "resolves_partial", resolvedTitle: "Kualitas Sitasi Karya Ilmiah pada Mahasiswa di Indonesia", titleSimilarity: 78 },
  },
  {
    id: 5,
    color: "#dc2626",
    title: "Automated Reasoning in Large-Scale Academic Databases",
    authors: "Prasetya, A., & Hidayat, M.",
    year: 2022,
    doi: "10.1234/fake.doi.2022.001",
    status: "halu",
    citedAt: [4],
    citedInText: true,
    haluReasons: [
      "DOI tidak ditemukan di Crossref, OpenAlex, maupun Semantic Scholar.",
      "Tidak ada jejak publikasi dengan judul dan penulis ini di internet.",
    ],
    doiCheck: { status: "not_found" },
  },
  {
    id: 6,
    color: "#0891b2",
    title: "Large Language Models as Research Assistants: Opportunities and Risks",
    authors: "Kurniawan, F., & Anggraini, S.",
    year: 2023,
    doi: "10.1016/j.jrp.2023.104521",
    status: "valid",
    citedAt: [5],
    citedInText: true,
    doiCheck: { status: "resolves_match", resolvedTitle: "Large Language Models as Research Assistants: Opportunities and Risks", titleSimilarity: 94 },
  },
  {
    id: 7,
    color: "#be185d",
    title: "Academic Integrity in the Age of Generative AI",
    authors: "Wulandari, T.",
    year: 2023,
    doi: "10.3102/0013189X231234567",
    status: "valid",
    citedAt: [6],
    citedInText: true,
    doiCheck: { status: "resolves_match", resolvedTitle: "Academic Integrity in the Age of Generative AI", titleSimilarity: 97 },
  },
  {
    id: 8,
    color: "#ca8a04",
    title: "Evaluating Reference Accuracy in Undergraduate Theses",
    authors: "Nugroho, H., & Permata, I.",
    year: 2021,
    doi: "10.31219/osf.io/7yk2m",
    status: "warning",
    citedAt: [7],
    citedInText: true,
    warningReasons: ["Tahun terbit pada sumber asli tercatat 2020, bukan 2021."],
    doiCheck: { status: "resolves_partial", resolvedTitle: "Evaluating Citation Accuracy in Undergraduate Theses", titleSimilarity: 81 },
  },
];

type DocBlock =
  | { kind: "h1" | "h2" | "h3"; text: string }
  | { kind: "p"; segments: DocumentSegment[] };

const documentBlocks: DocBlock[] = [
  { kind: "h1", text: "BAB I" },
  { kind: "h2", text: "PENDAHULUAN" },
  { kind: "h3", text: "1.1 Latar Belakang" },
  {
    kind: "p",
    segments: [
      {
        type: "text",
        content:
          "Perkembangan teknologi kecerdasan buatan telah membawa perubahan signifikan dalam berbagai aspek kehidupan akademik. Penggunaan model bahasa besar (Large Language Models) sebagai alat bantu penulisan semakin meluas di kalangan mahasiswa ",
      },
      { type: "citation", content: "(Sutrisno & Rahardjo, 2020)", refId: 4 },
      {
        type: "text",
        content:
          ". Fenomena ini menimbulkan berbagai tantangan baru dalam penjaminan mutu akademik, khususnya dalam hal keotentikan daftar pustaka yang dicantumkan dalam karya ilmiah.",
      },
    ],
  },
  {
    kind: "p",
    segments: [
      {
        type: "text",
        content: "Penelitian terdahulu menunjukkan bahwa model deep learning telah berhasil diaplikasikan dalam berbagai tugas pemrosesan bahasa alami ",
      },
      { type: "citation", content: "(LeCun, Bengio & Hinton, 2019)", refId: 1 },
      { type: "text", content: ". Arsitektur transformer yang diperkenalkan oleh " },
      { type: "citation", content: "(Vaswani et al., 2017)", refId: 2 },
      {
        type: "text",
        content: " menjadi landasan bagi banyak model bahasa modern, termasuk BERT yang dikembangkan untuk pemahaman bahasa dua arah ",
      },
      { type: "citation", content: "(Devlin et al., 2019)", refId: 3 },
      { type: "text", content: "." },
    ],
  },
  {
    kind: "p",
    segments: [
      {
        type: "text",
        content:
          "Namun, penggunaan model bahasa besar dalam penulisan akademik juga membuka celah baru berupa referensi yang tampak meyakinkan namun sebenarnya tidak pernah ada ",
      },
      { type: "citation", content: "(Prasetya & Hidayat, 2022)", refId: 5, isHalu: true },
      { type: "text", content: ", sehingga diperlukan mekanisme validasi otomatis terhadap setiap sitasi yang dicantumkan." },
    ],
  },
];

const statusMeta: Record<RefStatus, { label: string; bg: string; fg: string }> = {
  valid: { label: "Valid", bg: "#dcfce7", fg: "#15803d" },
  warning: { label: "Perlu Dicek", bg: "#fef3c7", fg: "#b45309" },
  halu: { label: "Halu / Tidak Ditemukan", bg: "#fee2e2", fg: "#b91c1c" },
};

const filters: { key: FilterStatus; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "valid", label: "Valid" },
  { key: "warning", label: "Perlu Dicek" },
  { key: "halu", label: "Halu" },
];

const activeFilter = ref<FilterStatus>("all");
const selectedRefId = ref<number | null>(null);
const expandedIds = reactive<Set<number>>(new Set());
const refItemEls = ref<Record<number, HTMLElement | null>>({});

const counts = computed(() => ({
  total: references.length,
  valid: references.filter((r) => r.status === "valid").length,
  warning: references.filter((r) => r.status === "warning").length,
  halu: references.filter((r) => r.status === "halu").length,
}));

const trustScore = computed(() => {
  if (counts.value.total === 0) return 0;
  const weighted = counts.value.valid + counts.value.warning * 0.5;
  return Math.round((weighted / counts.value.total) * 100);
});

// SVG ring math for the trust-score donut.
const RING_RADIUS = 22;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - trustScore.value / 100));

const filteredReferences = computed(() => {
  if (activeFilter.value === "all") return references;
  return references.filter((r) => r.status === activeFilter.value);
});

function hexToRgba(hex: string, alpha: number) {
  const parsed = hex.replace("#", "");
  const r = parseInt(parsed.substring(0, 2), 16);
  const g = parseInt(parsed.substring(2, 4), 16);
  const b = parseInt(parsed.substring(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function citationStyle(refId: number | null | undefined) {
  const reference = references.find((r) => r.id === refId);
  const color = reference?.color ?? "#6b7a99";
  const isSelected = selectedRefId.value === refId;
  return {
    background: hexToRgba(color, isSelected ? 0.45 : 0.22),
    color: "var(--foreground)",
    boxShadow: isSelected ? `0 0 0 1.5px ${color}` : "none",
  };
}

function selectCitation(refId: number | null | undefined) {
  if (refId == null) return;
  selectedRefId.value = refId;
  activeFilter.value = "all";
  expandedIds.add(refId);
  requestAnimationFrame(() => {
    refItemEls.value[refId]?.scrollIntoView({ behavior: "smooth", block: "center" });
  });
}

function toggleExpanded(id: number) {
  if (expandedIds.has(id)) expandedIds.delete(id);
  else expandedIds.add(id);
}

function doiCheckLabel(reference: Reference) {
  switch (reference.doiCheck.status) {
    case "resolves_match":
      return `Cocok dengan sumber asli (kemiripan judul ${reference.doiCheck.titleSimilarity}%).`;
    case "resolves_partial":
      return `Sebagian cocok dengan sumber asli (kemiripan judul ${reference.doiCheck.titleSimilarity}%).`;
    case "resolves_mismatch":
      return "DOI ditemukan tetapi judul tidak cocok dengan sumber asli.";
    case "not_found":
      return "DOI tidak ditemukan di database manapun.";
    case "no_doi":
      return "Referensi ini tidak mencantumkan DOI.";
    default:
      return "";
  }
}

function goBack() {
  router.push({ name: "upload" });
}

function downloadReport() {
  // TODO: sambungkan ke endpoint export PDF laporan hasil.
}
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
            :style="{ background: statusMeta.warning.bg, color: statusMeta.warning.fg }"
          >
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{
              counts.warning
            }}</span>
            Perlu Dicek
          </span>

          <span
            class="flex items-center gap-2 text-sm font-bold px-3 py-1.5 rounded-full"
            :style="{ background: statusMeta.halu.bg, color: statusMeta.halu.fg }"
          >
            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-extrabold" :style="{ background: 'white' }">{{
              counts.halu
            }}</span>
            Halu / Tidak Ditemukan
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
            class="flex items-center gap-2 text-sm font-bold px-5 py-2.5 rounded-full text-white flex-shrink-0"
            :style="{ background: 'var(--primary)' }"
            @click="downloadReport"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <path d="M12 3v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M3 15v4a2 2 0 002 2h14a2 2 0 002-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Unduh Laporan
          </button>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-6 py-8 grid grid-cols-1 lg:grid-cols-5 gap-8">
      <div class="lg:col-span-3">
        <p class="text-xs font-bold tracking-widest mb-4" :style="{ color: 'var(--muted-foreground)' }">
          ISI DOKUMEN
        </p>

        <div class="flex flex-col gap-4">
          <template v-for="(block, index) in documentBlocks" :key="index">
            <h2 v-if="block.kind === 'h1'" class="text-base font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">
              {{ block.text }}
            </h2>
            <h3 v-else-if="block.kind === 'h2'" class="text-base font-extrabold" :style="{ color: 'var(--foreground)' }">
              {{ block.text }}
            </h3>
            <h4 v-else-if="block.kind === 'h3'" class="text-sm font-bold mt-2" :style="{ color: 'var(--foreground)' }">
              {{ block.text }}
            </h4>
            <p v-else class="text-sm leading-8" :style="{ color: 'var(--foreground)' }">
              <template v-for="(segment, sIndex) in block.segments" :key="sIndex">
                <span v-if="segment.type === 'text'">{{ segment.content }}</span>
                <button
                  v-else
                  class="mx-0.5 px-1 rounded transition-shadow cursor-pointer"
                  :style="citationStyle(segment.refId)"
                  @click="selectCitation(segment.refId)"
                >
                  {{ segment.content }}
                </button>
              </template>
            </p>
          </template>
        </div>
      </div>

      <div class="lg:col-span-2">
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
            class="rounded-xl border-l-4 border border-t border-r border-b overflow-hidden"
            :style="{
              borderLeftColor: reference.color,
              borderTopColor: 'var(--border)',
              borderRightColor: 'var(--border)',
              borderBottomColor: 'var(--border)',
              background: 'var(--card)',
              outline: selectedRefId === reference.id ? `2px solid ${reference.color}` : 'none',
              outlineOffset: '1px',
            }"
          >
            <div class="p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-2.5 min-w-0">
                  <span
                    class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-extrabold mt-0.5"
                    :style="{ background: hexToRgba(reference.color, 0.18), color: reference.color }"
                  >
                    {{ reference.id }}
                  </span>
                  <p class="text-sm font-bold leading-snug" :style="{ color: 'var(--foreground)' }">
                    {{ reference.title }}
                  </p>
                </div>

                <span
                  class="flex-shrink-0 text-[11px] font-bold px-2.5 py-1 rounded-full"
                  :style="{ background: statusMeta[reference.status].bg, color: statusMeta[reference.status].fg }"
                >
                  {{ statusMeta[reference.status].label }}
                </span>
              </div>

              <p class="text-xs mt-2 ml-8" :style="{ color: 'var(--muted-foreground)' }">
                {{ reference.authors }}
              </p>
              <p class="text-xs ml-8" :style="{ color: 'var(--muted-foreground)' }">{{ reference.year }}</p>
              <a
                :href="`https://doi.org/${reference.doi}`"
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

              <div v-if="expandedIds.has(reference.id)" class="mt-2 text-xs rounded-lg px-3 py-2.5" :style="{ background: 'var(--muted)', color: 'var(--foreground)' }">
                <p>{{ doiCheckLabel(reference) }}</p>
                <ul
                  v-if="reference.warningReasons?.length || reference.haluReasons?.length"
                  class="mt-2 flex flex-col gap-1"
                  :style="{ color: statusMeta[reference.status].fg }"
                >
                  <li v-for="(reason, i) in reference.warningReasons ?? reference.haluReasons" :key="i">&bull; {{ reason }}</li>
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
</template>