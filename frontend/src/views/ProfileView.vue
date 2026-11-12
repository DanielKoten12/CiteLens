<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();

const name = ref(auth.user?.name ?? "");
const email = computed(() => auth.user?.email ?? "");
const institution = ref("");
const studyProgram = ref("");
const isSaved = ref(false);
const initial = computed(() => name.value.trim().charAt(0).toUpperCase() || "?");

/* ---------- Statistik dari riwayat ---------- */

const PER_PAGE = 15;
const MAX_PAGES = 20; // batas pengaman

const statsLoading = ref(true);
const statsError = ref(false);
const documentCount = ref(0);
const averageScore = ref<number | null>(null);

async function loadStats() {
  statsLoading.value = true;
  statsError.value = false;
  try {
    // Jumlah seluruh dokumen di riwayat (semua status).
    const all = await documentsApi.list({ page: 1, per_page: 1 });
    documentCount.value = all.meta.total;

    if (all.meta.total === 0) {
      averageScore.value = null;
      return;
    }

    // Skor hanya dari dokumen yang selesai dianalisis.
    // Rumus sama dengan ResultView: (valid + perlu dicek * 0,5) / total referensi,
    // diakumulasi dari semua dokumen.
    let points = 0;
    let refs = 0;
    let page = 1;
    let lastPage = 1;
    do {
      const res = await documentsApi.list({ page, per_page: PER_PAGE, status: "completed" });
      lastPage = res.meta.last_page;
      for (const doc of res.data) {
        const s = doc.summary;
        if (doc.status !== "completed" || !s || !s.total_references) continue;
        points += (s.valid ?? 0) + (s.suspicious ?? 0) * 0.5;
        refs += s.total_references;
      }
      page += 1;
    } while (page <= lastPage && page <= MAX_PAGES);

    averageScore.value = refs > 0 ? Math.round((points / refs) * 100) : null;
  } catch (e) {
    statsError.value = true;
    if (!(e instanceof ApiError)) console.error(e);
  } finally {
    statsLoading.value = false;
  }
}

onMounted(loadStats);

const documentLabel = computed(() => (statsLoading.value || statsError.value ? "—" : String(documentCount.value)));
const scoreLabel = computed(() => {
  if (statsLoading.value || statsError.value || averageScore.value === null) return "—";
  return `${averageScore.value}%`;
});

/* ---------- Form ---------- */

function saveProfile() {
  if (auth.user) auth.user.name = name.value;
  isSaved.value = true;
  window.setTimeout(() => (isSaved.value = false), 2200);
}

function goBack() {
  router.push({ name: "upload" });
}
</script>

<template>
  <main class="max-w-5xl mx-auto w-full px-4 sm:px-6 py-8 sm:py-10">
    <button class="inline-flex items-center gap-2 text-sm font-bold mb-8" :style="{ color: 'var(--muted-foreground)' }" @click="goBack">
      <span class="text-lg">&lsaquo;</span> Kembali ke Beranda
    </button>

    <div class="mb-8">
      <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">AKUN</p>
      <h1 class="text-2xl font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">Profil Saya</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">Kelola informasi akun dan identitas akademik kamu.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <section class="rounded-2xl border p-6 lg:col-span-1" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="flex flex-col items-center text-center">
          <div
            class="w-20 h-20 rounded-full flex items-center justify-center text-2xl font-extrabold"
            :style="{ background: 'var(--primary)', color: 'var(--primary-foreground)' }"
          >
            {{ initial }}
          </div>
          <h2 class="text-lg font-extrabold mt-4 break-words max-w-full" :style="{ color: 'var(--foreground)' }">{{ name || "Pengguna" }}</h2>
          <p class="text-sm mt-1 break-all" :style="{ color: 'var(--muted-foreground)' }">{{ email }}</p>
          <span class="status-badge mt-5">
            <span class="status-badge-dot" /> Akun aktif
          </span>
        </div>

        <div class="h-px my-6" :style="{ background: 'var(--border)' }" />

        <div class="grid grid-cols-2 gap-3 text-center">
          <div class="rounded-xl p-3" :style="{ background: 'var(--muted)' }">
            <p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">{{ documentLabel }}</p>
            <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">Dokumen</p>
          </div>
          <div class="rounded-xl p-3" :style="{ background: 'var(--muted)' }">
            <p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">{{ scoreLabel }}</p>
            <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">Rata-rata skor</p>
          </div>
        </div>

        <p v-if="statsError" class="text-xs text-center mt-3" :style="{ color: 'var(--muted-foreground)' }">
          Statistik gagal dimuat.
          <button class="font-bold underline underline-offset-2" @click="loadStats">Coba lagi</button>
        </p>
      </section>

      <section class="rounded-2xl border p-6 lg:col-span-2" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="flex items-start justify-between gap-4 mb-6">
          <div>
            <h2 class="text-base font-extrabold" :style="{ color: 'var(--foreground)' }">Informasi akun</h2>
            <p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Perbarui detail profil yang tampil di aplikasi.</p>
          </div>
          <span v-if="isSaved" class="saved-text text-xs font-bold">Tersimpan</span>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="saveProfile">
          <label class="flex flex-col gap-2">
            <span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Nama lengkap</span>
            <input
              v-model="name"
              class="field h-11 rounded-xl border px-3.5 text-sm focus:outline-none"
              placeholder="Nama lengkap kamu"
              :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }"
            />
          </label>

          <label class="flex flex-col gap-2">
            <span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Email</span>
            <input
              :value="email"
              disabled
              class="h-11 rounded-xl border px-3.5 text-sm opacity-70"
              :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--muted)' }"
            />
          </label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Institusi</span>
              <input
                v-model="institution"
                class="field h-11 rounded-xl border px-3.5 text-sm focus:outline-none"
                placeholder="Contoh: Universitas Indonesia"
                :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }"
              />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Program studi</span>
              <input
                v-model="studyProgram"
                class="field h-11 rounded-xl border px-3.5 text-sm focus:outline-none"
                placeholder="Contoh: Teknik Informatika"
                :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }"
              />
            </label>
          </div>

          <div class="flex justify-end pt-2">
            <button
              type="submit"
              class="px-5 py-2.5 rounded-xl text-sm font-bold"
              :style="{ background: 'var(--primary)', color: 'var(--primary-foreground)' }"
            >
              Simpan Perubahan
            </button>
          </div>
        </form>
      </section>
    </div>
  </main>
</template>

<style scoped>
.field::placeholder {
  color: var(--muted-foreground);
  opacity: 0.7;
}
.field:focus {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent);
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.75rem;
  border-radius: 9999px;
  background: #dcfce7;
  color: #15803d;
  font-size: 0.75rem;
  font-weight: 700;
}
.status-badge-dot {
  width: 0.375rem;
  height: 0.375rem;
  border-radius: 9999px;
  background: #16a34a;
}
.dark .status-badge {
  background: rgba(34, 197, 94, 0.15);
  color: #4ade80;
}
.dark .status-badge-dot {
  background: #4ade80;
}

.saved-text {
  color: #15803d;
}
.dark .saved-text {
  color: #4ade80;
}
</style>