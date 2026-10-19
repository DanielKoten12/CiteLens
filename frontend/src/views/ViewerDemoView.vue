<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import PdfHighlightViewer from "@/components/viewer/PdfHighlightViewer.vue";
import { useDocumentHighlights } from "@/composables/useDocumentHighlights";
import { ApiError } from "@/lib/api";

const route = useRoute();
const viewer = ref<InstanceType<typeof PdfHighlightViewer> | null>(null);
const selectedId = ref<string | null>(null);
const isDev = import.meta.env.DEV;
const documentId = typeof route.query.document === "string" ? route.query.document : undefined;
const usingMock = !documentId;
const {
  document,
  highlights,
  loading,
  error,
  load,
} = useDocumentHighlights(documentId, { mock: usingMock });
const source = computed(() => document.value?.file?.url ?? "/sample.pdf");
const errorMessage = computed(() => {
  if (!error.value) return "";
  if (error.value instanceof ApiError) {
    return error.value.detailMessages;
  }
  return error.value.message;
});
const debugPages = computed(() => viewer.value?.debugPages ?? []);

onMounted(() => {
  void load();
});

function selectHighlight(item: { id: string; kind: "reference" | "citation" }) {
  selectedId.value = item.id;
}

function focusHighlight(id: string) {
  selectedId.value = id;
  viewer.value?.scrollToHighlight(id);
}
</script>

<template>
  <main class="mx-auto max-w-7xl space-y-5 px-6 py-8">
    <div>
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-600">Demo internal</p>
      <h1 class="mt-2 text-2xl font-extrabold text-slate-900">PDF viewer dan highlight sitasi</h1>
      <p class="mt-1 text-sm text-slate-500">
        {{ usingMock ? "Fixture: /sample.pdf" : `Dokumen: ${document?.name ?? documentId}` }}
      </p>
    </div>
    <div v-if="loading" class="rounded-xl border bg-white p-4 text-sm text-slate-500">
      Memuat dokumen dan lokasi highlight...
    </div>
    <div v-else-if="error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ errorMessage }}
    </div>
    <div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_260px]">
      <PdfHighlightViewer
        ref="viewer"
        :src="source"
        :highlights="highlights"
        :selected-id="selectedId"
        @select="selectHighlight"
      />
      <aside class="max-h-[620px] overflow-auto rounded-xl border bg-white p-3">
        <h2 class="mb-2 px-2 text-sm font-bold text-slate-800">Highlight ({{ highlights.length }})</h2>
        <button
          v-for="item in highlights"
          :key="item.id"
          class="mb-1 block w-full truncate rounded-md px-2 py-2 text-left text-xs text-slate-600 hover:bg-slate-100"
          :class="{ 'bg-blue-50 font-bold text-blue-700': selectedId === item.id }"
          @click="focusHighlight(item.id)"
        >
          {{ item.kind === "reference" ? "Referensi" : "Sitasi" }} · {{ item.status }} · {{ item.id }}
        </button>
      </aside>
    </div>
    <details v-if="isDev" class="rounded-xl border bg-white p-3 text-xs">
      <summary class="cursor-pointer font-bold text-slate-800">Debug render per halaman</summary>
      <div class="mt-2 overflow-auto">
        <table class="w-full text-left">
          <thead><tr><th>Hal</th><th>Status</th><th>CSS</th><th>Canvas</th><th>Style</th><th>Key</th></tr></thead>
          <tbody>
            <tr v-for="(page, index) in debugPages" :key="index">
              <td>{{ index + 1 }}</td><td>{{ page.status }}</td><td>{{ page.cssWidth.toFixed(1) }}</td>
              <td>{{ page.canvasWidth }}</td><td>{{ page.canvasStyleWidth }}</td><td>{{ page.key }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>
  </main>
</template>
