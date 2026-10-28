import { computed, ref } from "vue";
import { defineStore } from "pinia";

export const useDocumentStore = defineStore("document", () => {
  const file = ref<File | null>(null);
  const objectUrl = ref("");

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
  }

  return { file, objectUrl, fileName, isPdf, setFile, clearFile };
});
