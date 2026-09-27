<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import type { HistoryEntry } from "@/types";

const router = useRouter();
const query = ref("");

const documents = ref<HistoryEntry[]>([
  { id: "d1", fileName: "Skripsi_Andi_Firmansyah_2024.pdf", uploadedAt: "14:32", date: "7 Sep 2024", timestamp: 1725693120000, totalRefs: 32, valid: 26, warning: 4, halu: 2, trustScore: 81, size: "2.4 MB", pages: 87 },
  { id: "d2", fileName: "Proposal_TA_Dewi_Kusumawati.docx", uploadedAt: "11:20", date: "2 Sep 2024", timestamp: 1725261600000, totalRefs: 12, valid: 11, warning: 1, halu: 0, trustScore: 92, size: "0.8 MB", pages: 28 },
  { id: "d3", fileName: "Revisi_Skripsi_Final_Bagas.pdf", uploadedAt: "20:05", date: "31 Agu 2024", timestamp: 1725123900000, totalRefs: 55, valid: 38, warning: 9, halu: 8, trustScore: 69, size: "5.1 MB", pages: 134 },
]);

const filteredDocuments = computed(() => {
  const value = query.value.trim().toLowerCase();
  return documents.value.filter((document) => !value || document.fileName.toLowerCase().includes(value));
});

function scoreColor(score: number) {
  return score >= 90 ? "#16a34a" : score >= 70 ? "#d97706" : "#dc2626";
}

function fileKind(name: string) {
  return name.toLowerCase().endsWith(".pdf") ? "pdf" : "docx";
}

function openDocument() {
  router.push({ name: "result" });
}

function removeDocument(id: string) {
  if (confirm("Hapus dokumen ini dari daftar?")) documents.value = documents.value.filter((document) => document.id !== id);
}
</script>

<template>
  <main class="max-w-7xl mx-auto w-full px-6 py-10">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-7">
      <div>
        <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">ARSIP DOKUMEN</p>
        <h1 class="text-2xl font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">Dokumen Saya</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">Semua dokumen yang pernah kamu periksa tersimpan di sini.</p>
      </div>
      <button class="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-white" :style="{ background: 'var(--primary)' }" @click="router.push({ name: 'upload' })">
        <span class="text-base">+</span> Periksa Dokumen Baru
      </button>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
      <div class="relative w-full sm:max-w-sm"><span class="absolute left-3.5 top-1/2 -translate-y-1/2" :style="{ color: 'var(--muted-foreground)' }">&#128269;</span><input v-model="query" placeholder="Cari nama file..." class="w-full h-11 rounded-xl border pl-10 pr-4 text-sm focus:outline-none" :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--foreground)' }" /></div>
      <p class="text-sm" :style="{ color: 'var(--muted-foreground)' }">{{ filteredDocuments.length }} dokumen</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
      <article v-for="document in filteredDocuments" :key="document.id" class="rounded-2xl border p-5" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="flex items-start justify-between gap-3">
          <div class="w-11 h-11 rounded-xl flex items-center justify-center font-extrabold text-xs" :style="{ background: fileKind(document.fileName) === 'pdf' ? '#fee2e2' : '#dbeafe', color: fileKind(document.fileName) === 'pdf' ? '#b91c1c' : '#1d4ed8' }">{{ fileKind(document.fileName).toUpperCase() }}</div>
          <span class="text-xs font-bold px-2.5 py-1 rounded-full" :style="{ background: document.trustScore >= 90 ? '#dcfce7' : document.trustScore >= 70 ? '#fef3c7' : '#fee2e2', color: scoreColor(document.trustScore) }">{{ document.trustScore }}% terpercaya</span>
        </div>
        <h2 class="font-extrabold leading-snug mt-5 break-words" :style="{ color: 'var(--foreground)' }">{{ document.fileName }}</h2>
        <p class="text-xs mt-2" :style="{ color: 'var(--muted-foreground)' }">{{ document.date }} &middot; {{ document.size }} &middot; {{ document.pages }} halaman</p>
        <div class="h-px my-5" :style="{ background: 'var(--border)' }" />
        <div class="grid grid-cols-3 gap-2 text-center"><div><p class="text-base font-extrabold" :style="{ color: '#15803d' }">{{ document.valid }}</p><p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Valid</p></div><div><p class="text-base font-extrabold" :style="{ color: '#b45309' }">{{ document.warning }}</p><p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Dicek</p></div><div><p class="text-base font-extrabold" :style="{ color: '#b91c1c' }">{{ document.halu }}</p><p class="text-[11px]" :style="{ color: 'var(--muted-foreground)' }">Halu</p></div></div>
        <div class="flex gap-2 mt-5"><button class="flex-1 rounded-xl py-2.5 text-xs font-bold text-white" :style="{ background: 'var(--primary)' }" @click="openDocument">Buka Hasil</button><button class="w-10 rounded-xl flex items-center justify-center" style="background: #fee2e2; color: #b91c1c" aria-label="Hapus dokumen" @click="removeDocument(document.id)">&#128465;</button></div>
      </article>
    </div>
    <div v-if="filteredDocuments.length === 0" class="rounded-2xl border py-16 text-center" :style="{ borderColor: 'var(--border)', background: 'var(--card)', color: 'var(--muted-foreground)' }">Tidak ada dokumen yang cocok.</div>
  </main>
</template>
