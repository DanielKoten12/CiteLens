<script setup lang="ts">
import { onMounted, ref } from "vue";
import PdfHighlightViewer from "@/components/viewer/PdfHighlightViewer.vue";
import { useHighlights } from "@/composables/useHighlights";
import { getCitations, getReferences } from "@/services/documentsApi";
import type { Citation, Reference } from "@/types/viewer";

const viewer = ref<InstanceType<typeof PdfHighlightViewer> | null>(null);
const references = ref<Reference[]>([]);
const citations = ref<Citation[]>([]);
const selectedId = ref<string | null>(null);
const { highlights } = useHighlights(references, citations);

onMounted(async () => {
  const [referenceResponse, citationResponse] = await Promise.all([getReferences(), getCitations()]);
  references.value = referenceResponse.data;
  citations.value = citationResponse.data;
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
      <p class="mt-1 text-sm text-slate-500">Fixture: <code>/sample.pdf</code></p>
    </div>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_260px]">
      <PdfHighlightViewer
        ref="viewer"
        src="/sample.pdf"
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
  </main>
</template>
