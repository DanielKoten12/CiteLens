import { computed, ref } from "vue";
import { defineStore } from "pinia";
import type { PdfHighlight } from "@/types";

export const useDocumentStore = defineStore("document", () => {
  const file = ref<File | null>(null);
  const objectUrl = ref("");
  const highlights = ref<PdfHighlight[]>([]);

  const fileName = computed(() => file.value?.name ?? "Dokumen belum dipilih");
  const isPdf = computed(() => file.value?.type === "application/pdf" || file.value?.name.toLowerCase().endsWith(".pdf"));

  function setFile(nextFile: File) {
    if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
    file.value = nextFile;
    objectUrl.value = URL.createObjectURL(nextFile);
  }

  function clearFile() {
    if (objectUrl.value) URL.revokeObjectURL(objectUrl.value);
    objectUrl.value = "";
    file.value = null;
    highlights.value = [];
  }

  function setHighlights(nextHighlights: PdfHighlight[]) {
    highlights.value = nextHighlights;
  }

  return { file, objectUrl, fileName, isPdf, highlights, setFile, setHighlights, clearFile };
});
