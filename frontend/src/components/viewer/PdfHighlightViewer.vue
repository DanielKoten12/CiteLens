<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from "vue";
import type { ComponentPublicInstance } from "vue";
import { ChevronLeft, ChevronRight, Minus, Plus, RotateCcw } from "lucide-vue-next";
import { getDocument, GlobalWorkerOptions, type PDFDocumentProxy, type PDFPageProxy } from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import type { HighlightItem } from "@/types/viewer";

GlobalWorkerOptions.workerSrc = workerUrl;

const props = defineProps<{
  src: string;
  highlights?: HighlightItem[];
  selectedId?: string | null;
}>();

const emit = defineEmits<{
  select: [item: Pick<HighlightItem, "id" | "kind">];
}>();

const container = ref<HTMLElement | null>(null);
const pageRefs = ref<HTMLElement[]>([]);
const canvases = ref<HTMLCanvasElement[]>([]);
const pdf = shallowRef<PDFDocumentProxy | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const pageNumber = ref(1);
const scale = ref(1);
const pageMetrics = ref<Array<{ width: number; height: number; renderWidth: number; renderHeight: number }>>([]);

const pageLabel = computed(() =>
  pdf.value ? `Halaman ${pageNumber.value} dari ${pdf.value.numPages}` : "Memuat PDF...",
);

function setCanvasRef(element: Element | ComponentPublicInstance | null, index: number) {
  if (element instanceof HTMLCanvasElement) canvases.value[index] = element;
}

function setPageRef(element: Element | ComponentPublicInstance | null, index: number) {
  if (element instanceof HTMLElement) pageRefs.value[index] = element;
}

function highlightsForPage(page: number) {
  return (props.highlights ?? []).flatMap((item) =>
    item.locations
      .filter((location) => location.page_number === page)
      .map((location) => ({ item, location })),
  );
}

function highlightStyle(item: HighlightItem, location: HighlightItem["locations"][number], pageIndex: number) {
  const metric = pageMetrics.value[pageIndex];
  const width = metric?.width || location.page_width;
  const ratio = (metric?.renderWidth || 0) / width;
  const selected = props.selectedId === item.id;
  return {
    left: `${location.x * ratio}px`,
    top: `${location.y * ratio}px`,
    width: `${location.width * ratio}px`,
    height: `${location.height * ratio}px`,
    "--highlight-color": colorFor(item),
    outline: selected ? "2px solid var(--highlight-color)" : "none",
  };
}

function colorFor(item: HighlightItem) {
  if (item.kind === "reference") {
    const colors: Record<string, string> = {
      valid: "var(--highlight-reference-valid)",
      suspicious: "var(--highlight-reference-suspicious)",
      invalid: "var(--highlight-reference-invalid)",
      not_found: "var(--highlight-reference-invalid)",
      pending: "var(--highlight-reference-pending)",
    };
    return colors[item.status] ?? "var(--highlight-reference-pending)";
  }
  const colors: Record<string, string> = {
    hallucination: "var(--highlight-citation-hallucination)",
    unreliable: "var(--highlight-citation-unreliable)",
    pending: "var(--highlight-citation-pending)",
    valid: "var(--highlight-citation-valid)",
  };
  return colors[item.status] ?? "var(--highlight-citation-pending)";
}

async function renderPage(page: PDFPageProxy, index: number) {
  const canvas = canvases.value[index];
  const shell = pageRefs.value[index];
  if (!canvas || !shell) return;

  const baseViewport = page.getViewport({ scale: 1 });
  const availableWidth = Math.max((container.value?.clientWidth ?? baseViewport.width) - 32, 280);
  const viewport = page.getViewport({ scale: (availableWidth / baseViewport.width) * scale.value });
  const pixelRatio = Math.min(window.devicePixelRatio || 1, 3);
  const context = canvas.getContext("2d");
  if (!context) throw new Error("Canvas PDF tidak tersedia di browser ini.");

  canvas.width = Math.ceil(viewport.width * pixelRatio);
  canvas.height = Math.ceil(viewport.height * pixelRatio);
  canvas.style.width = `${viewport.width}px`;
  canvas.style.height = `${viewport.height}px`;
  shell.style.width = `${viewport.width}px`;
  shell.style.height = `${viewport.height}px`;
  context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
  await page.render({ canvas: null, canvasContext: context, viewport }).promise;
  pageMetrics.value[index] = {
    width: baseViewport.width,
    height: baseViewport.height,
    renderWidth: viewport.width,
    renderHeight: viewport.height,
  };
}

async function renderAllPages() {
  if (!pdf.value) return;
  await nextTick();
  for (let index = 0; index < pdf.value.numPages; index += 1) {
    await renderPage(await pdf.value.getPage(index + 1), index);
  }
}

async function loadPdf() {
  loading.value = true;
  error.value = null;
  void pdf.value?.cleanup();
  pageRefs.value = [];
  canvases.value = [];
  pageMetrics.value = [];
  try {
    pdf.value = await getDocument({ url: props.src }).promise;
    pageNumber.value = 1;
    await renderAllPages();
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : "PDF tidak dapat dimuat.";
  } finally {
    loading.value = false;
  }
}

function changeZoom(delta: number) {
  scale.value = Math.min(2.5, Math.max(0.6, Number((scale.value + delta).toFixed(1))));
  void renderAllPages();
}

function resetZoom() {
  scale.value = 1;
  void renderAllPages();
}

function goToPage(nextPage: number) {
  if (!pdf.value) return;
  pageNumber.value = Math.min(pdf.value.numPages, Math.max(1, nextPage));
  pageRefs.value[pageNumber.value - 1]?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function scrollToHighlight(id: string) {
  const item = props.highlights?.find((highlight) => highlight.id === id);
  const page = item?.locations[0]?.page_number;
  if (!page) return;
  goToPage(page);
}

function handleResize() {
  void renderAllPages();
}

defineExpose({ scrollToHighlight });
watch(() => props.src, loadPdf);

onMounted(() => {
  window.addEventListener("resize", handleResize);
  void loadPdf();
});

onBeforeUnmount(() => {
  window.removeEventListener("resize", handleResize);
  void pdf.value?.cleanup();
});
</script>

<template>
  <section
    class="flex min-h-[620px] flex-col overflow-hidden rounded-xl border bg-slate-100 shadow-sm"
    style="--highlight-reference-valid: #22c55e; --highlight-reference-suspicious: #f59e0b; --highlight-reference-invalid: #ef4444; --highlight-reference-pending: #94a3b8; --highlight-citation-valid: #22c55e; --highlight-citation-hallucination: #eab308; --highlight-citation-unreliable: #fb7185; --highlight-citation-pending: #cbd5e1"
  >
    <header class="flex flex-wrap items-center justify-between gap-3 border-b bg-white px-4 py-3">
      <div class="flex items-center gap-2">
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="pageNumber <= 1" title="Halaman sebelumnya" @click="goToPage(pageNumber - 1)">
          <ChevronLeft :size="16" />
        </button>
        <span class="min-w-32 text-center text-sm font-semibold text-slate-700">{{ pageLabel }}</span>
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="!pdf || pageNumber >= pdf.numPages" title="Halaman berikutnya" @click="goToPage(pageNumber + 1)">
          <ChevronRight :size="16" />
        </button>
      </div>
      <div class="flex items-center gap-2">
        <button class="rounded-md border p-2" title="Perkecil" @click="changeZoom(-0.1)"><Minus :size="16" /></button>
        <span class="w-14 text-center text-sm text-slate-600">{{ Math.round(scale * 100) }}%</span>
        <button class="rounded-md border p-2" title="Perbesar" @click="changeZoom(0.1)"><Plus :size="16" /></button>
        <button class="rounded-md border p-2" title="Reset zoom" @click="resetZoom"><RotateCcw :size="16" /></button>
      </div>
    </header>

    <div ref="container" class="flex flex-1 justify-center overflow-auto p-4">
      <div v-if="loading" class="flex min-h-[560px] items-center text-sm text-slate-500">Memuat dokumen PDF...</div>
      <div v-else-if="error" class="flex min-h-[560px] max-w-md flex-col items-center justify-center gap-2 text-center text-sm text-red-700">
        <strong>PDF tidak dapat dimuat</strong>
        <span>{{ error }}</span>
      </div>
      <div v-else class="flex flex-col gap-4">
        <div
          v-for="page in pdf?.numPages"
          :key="page"
          :ref="(element) => setPageRef(element, page - 1)"
          class="relative shrink-0 bg-white shadow-md"
        >
          <canvas :ref="(element) => setCanvasRef(element, page - 1)" class="block" />
          <button
            v-for="{ item, location } in highlightsForPage(page)"
            :key="`${item.id}-${location.location_index}`"
            class="absolute z-10 cursor-pointer rounded-sm border border-[var(--highlight-color)] bg-[var(--highlight-color)]/35 p-0 transition hover:brightness-90"
            :style="highlightStyle(item, location, page - 1)"
            :title="`${item.kind === 'reference' ? 'Referensi' : 'Sitasi'}: ${item.status}`"
            @click="emit('select', { id: item.id, kind: item.kind })"
          />
        </div>
      </div>
    </div>
  </section>
</template>
