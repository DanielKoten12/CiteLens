<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from "vue";
import type { ComponentPublicInstance } from "vue";
import { ChevronLeft, ChevronRight, Minus, Plus, RotateCcw } from "lucide-vue-next";
import {
  getDocument,
  GlobalWorkerOptions,
  type PDFDocumentProxy,
  type PDFPageProxy,
  type RenderTask,
} from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import type { HighlightItem } from "@/types/viewer";
import { locationToPixels } from "./geometry";

GlobalWorkerOptions.workerSrc = workerUrl;

const props = defineProps<{
  src: string;
  highlights?: HighlightItem[];
  selectedId?: string | null;
  maxPageWidth?: number;
}>();

const emit = defineEmits<{
  select: [item: Pick<HighlightItem, "id" | "kind">];
}>();

type PageStatus = "placeholder" | "rendering" | "rendered";
type PageState = { status: PageStatus; key: string; token: number };
type PageMetric = {
  width: number;
  height: number;
  cssWidth: number;
  cssHeight: number;
  scale: number;
};
type DebugPage = PageState & {
  cssWidth: number;
  canvasWidth: number;
  canvasStyleWidth: string;
};

const container = ref<HTMLElement | null>(null);
const pageRefs = ref<HTMLElement[]>([]);
const canvases = ref<HTMLCanvasElement[]>([]);
const pdf = shallowRef<PDFDocumentProxy | null>(null);
const pages = shallowRef<PDFPageProxy[]>([]);
const pageMetrics = ref<PageMetric[]>([]);
const pageStates = ref<PageState[]>([]);
const visiblePages = ref(new Set<number>());
const loading = ref(true);
const error = ref<string | null>(null);
const pageNumber = ref(1);
const pageInput = ref("1");
const zoom = ref(1);
const fitWidthValue = ref(0);
const dpr = ref(1);
const observer = ref<IntersectionObserver | null>(null);
const resizeObserver = ref<ResizeObserver | null>(null);
const renderTasks = new Map<number, RenderTask>();
const renderPromises = new Map<number, Promise<void>>();
let loadGeneration = 0;
let debounceTimer: number | undefined;
let dprMediaQuery: MediaQueryList | null = null;

const pageLabel = computed(() =>
  pdf.value ? `Halaman ${pageNumber.value} dari ${pdf.value.numPages}` : "Memuat PDF...",
);
const maxPageWidth = computed(() => props.maxPageWidth ?? 900);

const debugPages = computed<DebugPage[]>(() =>
  pageStates.value.map((state, index) => ({
    ...state,
    cssWidth: pageMetrics.value[index]?.cssWidth ?? 0,
    canvasWidth: canvases.value[index]?.width ?? 0,
    canvasStyleWidth: canvases.value[index]?.style.width ?? "",
  })),
);

function destroyPdf(document: PDFDocumentProxy) {
  const destroyable = document as PDFDocumentProxy & { destroy?: () => Promise<void> };
  return typeof destroyable.destroy === "function" ? destroyable.destroy() : document.cleanup();
}

function setCanvasRef(element: Element | ComponentPublicInstance | null, index: number) {
  if (element instanceof HTMLCanvasElement) canvases.value[index] = element;
}

function setPageRef(element: Element | ComponentPublicInstance | null, index: number) {
  if (element instanceof HTMLElement) {
    pageRefs.value[index] = element;
    element.dataset.page = String(index);
    if (observer.value) observer.value.observe(element);
  }
}

function highlightsForPage(page: number) {
  return (props.highlights ?? []).flatMap((item) =>
    item.locations
      .filter((location) => location.page_number === page)
      .map((location) => ({ item, location })),
  );
}

function metricFor(index: number) {
  return pageMetrics.value[index];
}

function highlightStyle(
  item: HighlightItem,
  location: HighlightItem["locations"][number],
  pageIndex: number,
) {
  const metric = metricFor(pageIndex);
  const rect = metric
    ? locationToPixels(location, metric.width, metric.cssWidth)
    : { left: 0, top: 0, width: 0, height: 0 };
  return {
    left: `${rect.left}px`,
    top: `${rect.top}px`,
    width: `${rect.width}px`,
    height: `${rect.height}px`,
    "--highlight-color": colorFor(item),
    outline: props.selectedId === item.id ? "2px solid var(--highlight-color)" : "none",
  };
}

function colorFor(item: HighlightItem) {
  if (item.kind === "reference") {
    return ({
      valid: "var(--highlight-reference-valid)",
      suspicious: "var(--highlight-reference-suspicious)",
      invalid: "var(--highlight-reference-invalid)",
      not_found: "var(--highlight-reference-invalid)",
      pending: "var(--highlight-reference-pending)",
    } as Record<string, string>)[item.status] ?? "var(--highlight-neutral)";
  }
  return ({
    hallucination: "var(--highlight-citation-hallucination)",
    unreliable: "var(--highlight-citation-unreliable)",
    unresolved: "var(--highlight-citation-unresolved)",
    pending: "var(--highlight-citation-pending)",
    valid: "var(--highlight-citation-valid)",
  } as Record<string, string>)[item.status] ?? "var(--highlight-neutral)";
}

function updateFitWidth() {
  const width = container.value?.clientWidth ?? 0;
  if (width <= 0) return;
  fitWidthValue.value = Math.min(maxPageWidth.value, Math.max(320, width - 32));
}

function updateDpr() {
  dpr.value = Math.min(window.devicePixelRatio || 1, 2);
}

function updateMetrics() {
  const fit = fitWidthValue.value;
  pageMetrics.value = pageMetrics.value.map((metric) => {
    const cssWidth = fit > 0 ? fit * zoom.value : 0;
    return {
      ...metric,
      cssWidth,
      cssHeight: cssWidth * (metric.height / metric.width),
      scale: cssWidth / metric.width,
    };
  });
}

function renderKey(index: number) {
  const metric = metricFor(index);
  return metric ? `${metric.cssWidth.toFixed(2)}x${dpr.value}` : "";
}

function cancelRender(index: number) {
  renderTasks.get(index)?.cancel();
}

function releaseCanvas(index: number) {
  const state = pageStates.value[index];
  if (!state) return;
  state.token += 1;
  state.status = "placeholder";
  state.key = "";
  cancelRender(index);
  renderTasks.delete(index);
  renderPromises.delete(index);
  const canvas = canvases.value[index];
  if (canvas) {
    canvas.width = 0;
    canvas.height = 0;
  }
}

async function ensureRendered(index: number) {
  if (!visiblePages.value.has(index) && !visiblePages.value.has(index - 1) && !visiblePages.value.has(index + 1)) return;
  const metric = metricFor(index);
  const page = pages.value[index];
  const canvas = canvases.value[index];
  const shell = pageRefs.value[index];
  const state = pageStates.value[index];
  if (!metric || metric.cssWidth <= 0 || !page || !canvas || !shell || !state) return;
  const key = renderKey(index);
  if (state.status === "rendered" && state.key === key) return;
  if (state.status === "rendering" && state.key === key) return;

  cancelRender(index);
  const token = ++state.token;
  state.status = "rendering";
  state.key = key;
  const generation = loadGeneration;
  const promise = (async () => {
    const viewport = page.getViewport({ scale: metric.scale * dpr.value });
    canvas.width = Math.ceil(viewport.width);
    canvas.height = Math.ceil(viewport.height);
    canvas.style.width = `${metric.cssWidth}px`;
    canvas.style.height = `${metric.cssHeight}px`;
    const context = canvas.getContext("2d");
    if (!context) throw new Error("Canvas PDF tidak tersedia di browser ini.");
    const task = page.render({ canvas: null, canvasContext: context, viewport });
    renderTasks.set(index, task);
    await task.promise;
    if (generation === loadGeneration && state.token === token && state.key === key) {
      state.status = "rendered";
    }
  })().catch((cause) => {
    if (cause?.name !== "RenderingCancelledException" && generation === loadGeneration && state.token === token) {
      state.status = "placeholder";
      state.key = "";
      error.value = cause instanceof Error ? cause.message : "Halaman PDF tidak dapat dirender.";
    }
  }).finally(() => {
    if (state.token === token) {
      renderTasks.delete(index);
      renderPromises.delete(index);
    }
  });
  renderPromises.set(index, promise);
}

function wantedPages() {
  const wanted = new Set<number>();
  visiblePages.value.forEach((index) => {
    for (let page = Math.max(0, index - 1); page <= Math.min(pageMetrics.value.length - 1, index + 1); page += 1) {
      wanted.add(page);
    }
  });
  return wanted;
}

function runRenderPipeline() {
  const wanted = wantedPages();
  for (let index = 0; index < pageMetrics.value.length; index += 1) {
    if (wanted.has(index)) void ensureRendered(index);
    else if (pageStates.value[index]?.status !== "placeholder") releaseCanvas(index);
  }
}

function scheduleRenderPipeline(debounced = false) {
  window.clearTimeout(debounceTimer);
  if (debounced) {
    debounceTimer = window.setTimeout(runRenderPipeline, 120);
  } else {
    runRenderPipeline();
  }
}

function updateActivePage() {
  const root = container.value;
  if (!root) return;
  const rootRect = root.getBoundingClientRect();
  const center = rootRect.top + rootRect.height / 2;
  let closest = 0;
  let distance = Number.POSITIVE_INFINITY;
  pageRefs.value.forEach((shell, index) => {
    const rect = shell.getBoundingClientRect();
    const value = center >= rect.top && center <= rect.bottom
      ? 0
      : Math.min(Math.abs(center - rect.top), Math.abs(center - rect.bottom));
    if (value < distance) {
      closest = index;
      distance = value;
    }
  });
  pageNumber.value = closest + 1;
  pageInput.value = String(pageNumber.value);
}

function updateVisible(index: number, isVisible: boolean) {
  const next = new Set(visiblePages.value);
  if (isVisible) next.add(index);
  else next.delete(index);
  visiblePages.value = next;
}

async function setupObserver() {
  await nextTick();
  observer.value?.disconnect();
  if (!container.value || pageRefs.value.length !== pageMetrics.value.length) return;
  observer.value = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        const index = Number((entry.target as HTMLElement).dataset.page);
        updateVisible(index, entry.isIntersecting);
      });
      updateActivePage();
    },
    { root: container.value, rootMargin: "0px", threshold: 0 },
  );
  pageRefs.value.forEach((shell) => observer.value?.observe(shell));
  if (!visiblePages.value.size) visiblePages.value = new Set([0]);
}

async function loadPdf() {
  const generation = ++loadGeneration;
  loading.value = true;
  error.value = null;
  observer.value?.disconnect();
  renderTasks.forEach((task) => task.cancel());
  renderTasks.clear();
  renderPromises.clear();
  if (pdf.value) await destroyPdf(pdf.value);
  pdf.value = null;
  pages.value = [];
  pageRefs.value = [];
  canvases.value = [];
  pageMetrics.value = [];
  pageStates.value = [];
  visiblePages.value = new Set();
  try {
    const loaded = await getDocument({ url: props.src }).promise;
    if (generation !== loadGeneration) {
      await destroyPdf(loaded);
      return;
    }
    const loadedPages: PDFPageProxy[] = [];
    const metrics: PageMetric[] = [];
    for (let index = 0; index < loaded.numPages; index += 1) {
      const page = await loaded.getPage(index + 1);
      const viewport = page.getViewport({ scale: 1 });
      loadedPages.push(page);
      metrics.push({ width: viewport.width, height: viewport.height, cssWidth: 0, cssHeight: 0, scale: 0 });
    }
    pdf.value = loaded;
    pages.value = loadedPages;
    pageMetrics.value = metrics;
    pageStates.value = metrics.map(() => ({ status: "placeholder", key: "", token: 0 }));
    await nextTick();
    updateFitWidth();
    updateMetrics();
    loading.value = false;
    await setupObserver();
    scheduleRenderPipeline();
  } catch (cause) {
    if (generation === loadGeneration) error.value = cause instanceof Error ? cause.message : "PDF tidak dapat dimuat.";
    loading.value = false;
  }
}

function changeZoom(delta: number) {
  zoom.value = Math.min(2, Math.max(0.5, Number((zoom.value + delta).toFixed(2))));
}

function fitWidth() {
  zoom.value = 1;
}

function resetZoom() {
  fitWidth();
}

function goToPage(nextPage: number) {
  if (!pdf.value) return;
  const page = Math.min(pdf.value.numPages, Math.max(1, Math.trunc(nextPage)));
  pageNumber.value = page;
  pageInput.value = String(page);
  pageRefs.value[page - 1]?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function submitPage() {
  const parsed = Number(pageInput.value);
  if (Number.isFinite(parsed)) goToPage(parsed);
  else pageInput.value = String(pageNumber.value);
}

function scrollToHighlight(id: string) {
  const item = props.highlights?.find((highlight) => highlight.id === id);
  const page = item?.locations[0]?.page_number;
  if (page) goToPage(page);
}

function handleScroll() {
  updateActivePage();
}

function handleResize() {
  updateFitWidth();
  updateDpr();
}

function handleDprChange() {
  updateDpr();
  dprMediaQuery?.removeEventListener("change", handleDprChange);
  dprMediaQuery = window.matchMedia(`(resolution: ${window.devicePixelRatio}dppx)`);
  dprMediaQuery.addEventListener("change", handleDprChange);
}

defineExpose({ scrollToHighlight, debugPages });
watch(() => props.src, loadPdf);
watch([visiblePages, zoom, fitWidthValue, dpr], ([, newZoom], [, oldZoom]) => {
  updateMetrics();
  scheduleRenderPipeline(newZoom !== oldZoom);
}, { deep: true });

onMounted(() => {
  updateDpr();
  container.value?.addEventListener("scroll", handleScroll, { passive: true });
  window.addEventListener("resize", handleResize);
  if (container.value) {
    resizeObserver.value = new ResizeObserver(updateFitWidth);
    resizeObserver.value.observe(container.value);
  }
  dprMediaQuery = window.matchMedia(`(resolution: ${window.devicePixelRatio}dppx)`);
  dprMediaQuery.addEventListener("change", handleDprChange);
  void loadPdf();
});

onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer);
  container.value?.removeEventListener("scroll", handleScroll);
  window.removeEventListener("resize", handleResize);
  resizeObserver.value?.disconnect();
  dprMediaQuery?.removeEventListener("change", handleDprChange);
  observer.value?.disconnect();
  renderTasks.forEach((task) => task.cancel());
  if (pdf.value) void destroyPdf(pdf.value);
});
</script>

<template>
  <section
    class="flex min-h-[620px] flex-col overflow-hidden rounded-xl border bg-slate-100 shadow-sm"
    style="--highlight-reference-valid: #22c55e; --highlight-reference-suspicious: #f59e0b; --highlight-reference-invalid: #ef4444; --highlight-reference-pending: #94a3b8; --highlight-citation-valid: #22c55e; --highlight-citation-hallucination: #eab308; --highlight-citation-unreliable: #fb7185; --highlight-citation-unresolved: #f97316; --highlight-citation-pending: #cbd5e1; --highlight-neutral: #94a3b8"
  >
    <header class="flex flex-wrap items-center justify-between gap-3 border-b bg-white px-4 py-3">
      <div class="flex items-center gap-2">
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="pageNumber <= 1" title="Halaman sebelumnya" @click="goToPage(pageNumber - 1)"><ChevronLeft :size="16" /></button>
        <form class="flex items-center gap-1" @submit.prevent="submitPage">
          <input v-model="pageInput" class="w-12 rounded-md border px-2 py-1 text-center text-sm" inputmode="numeric" aria-label="Nomor halaman" />
          <span class="text-sm text-slate-600">/ {{ pdf?.numPages ?? "..." }}</span>
        </form>
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="!pdf || pageNumber >= pdf.numPages" title="Halaman berikutnya" @click="goToPage(pageNumber + 1)"><ChevronRight :size="16" /></button>
      </div>
      <div class="flex items-center gap-2">
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="zoom <= 0.5" title="Perkecil" @click="changeZoom(-0.1)"><Minus :size="16" /></button>
        <span class="w-14 text-center text-sm text-slate-600">{{ Math.round(zoom * 100) }}%</span>
        <button class="rounded-md border p-2 disabled:opacity-40" :disabled="zoom >= 2" title="Perbesar" @click="changeZoom(0.1)"><Plus :size="16" /></button>
        <button class="rounded-md border px-2 py-1 text-xs" title="Sesuaikan lebar" @click="fitWidth">Fit width</button>
        <button class="rounded-md border p-2" title="Reset zoom" @click="resetZoom"><RotateCcw :size="16" /></button>
      </div>
    </header>

    <div ref="container" class="flex h-[560px] max-h-[560px] min-w-0 w-full min-h-0 flex-none justify-center overflow-auto p-4">
      <div v-if="error" class="flex min-h-[560px] max-w-md flex-col items-center justify-center gap-2 text-center text-sm text-red-700"><strong>PDF tidak dapat dimuat</strong><span>{{ error }}</span></div>
      <div v-else-if="!pdf" class="flex min-h-[560px] items-center text-sm text-slate-500">Memuat dokumen PDF...</div>
      <div v-else class="flex flex-col gap-4">
        <div v-for="page in pdf.numPages" :key="page" :ref="(element) => setPageRef(element, page - 1)" class="relative shrink-0 bg-white shadow-md" :style="{ width: `${metricFor(page - 1)?.cssWidth ?? 0}px`, height: `${metricFor(page - 1)?.cssHeight ?? 0}px` }">
          <canvas :ref="(element) => setCanvasRef(element, page - 1)" class="block" :style="{ width: `${metricFor(page - 1)?.cssWidth ?? 0}px`, height: `${metricFor(page - 1)?.cssHeight ?? 0}px` }" />
          <button v-for="{ item, location } in highlightsForPage(page)" :key="`${item.id}-${location.location_index}`" class="highlight-box absolute z-10 cursor-pointer bg-[var(--highlight-color)]/35 p-0 mix-blend-multiply transition-opacity hover:opacity-80" :style="highlightStyle(item, location, page - 1)" :title="`${item.kind === 'reference' ? 'Referensi' : 'Sitasi'}: ${item.status}`" @click="emit('select', { id: item.id, kind: item.kind })" />
        </div>
      </div>
    </div>
  </section>
</template>
