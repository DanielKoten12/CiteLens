<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";

const router = useRouter();
const compactResults = ref(false);
const isDarkMode = ref(false);
const saved = ref(false);

onMounted(() => {
  isDarkMode.value = localStorage.getItem("citelens-theme") === "dark";
  document.documentElement.classList.toggle("dark", isDarkMode.value);
});

function setTheme(dark: boolean) {
  isDarkMode.value = dark;
  document.documentElement.classList.toggle("dark", dark);
  localStorage.setItem("citelens-theme", dark ? "dark" : "light");
}

function saveSettings() {
  saved.value = true;
  window.setTimeout(() => (saved.value = false), 2200);
}
</script>

<template>
  <main class="max-w-4xl mx-auto w-full px-6 py-10">
    <button
      class="inline-flex items-center gap-2 text-sm font-bold mb-8"
      :style="{ color: 'var(--muted-foreground)' }"
      @click="router.push({ name: 'upload' })"
    >
      <span class="text-lg">&lsaquo;</span> Kembali ke Beranda
    </button>

    <div class="mb-8">
      <p class="text-xs font-bold tracking-widest" :style="{ color: 'var(--muted-foreground)' }">PREFERENSI</p>
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold mt-2" :style="{ color: 'var(--foreground)' }">Pengaturan</h1>
          <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">Atur pengalaman penggunaan CiteLens sesuai kebutuhanmu.</p>
        </div>
        <span v-if="saved" class="saved-text text-xs font-bold mt-2">Pengaturan tersimpan</span>
      </div>
    </div>

    <div class="flex flex-col gap-5">
      <section class="rounded-2xl border overflow-hidden" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="px-6 py-5 border-b" :style="{ borderColor: 'var(--border)' }">
          <h2 class="text-base font-extrabold" :style="{ color: 'var(--foreground)' }">Mode tampilan</h2>
          <p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Pilih tampilan yang paling nyaman untuk bekerja.</p>
        </div>
        <div class="px-6 py-5 flex flex-wrap items-center justify-between gap-5">
          <div>
            <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">Tema aplikasi</p>
            <p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Perubahan tema berlaku di seluruh halaman CiteLens.</p>
          </div>
          <div class="theme-options" role="group" aria-label="Mode tampilan">
            <button type="button" class="theme-option" :class="{ 'theme-option-active': !isDarkMode }" :aria-pressed="!isDarkMode" @click="setTheme(false)"><span aria-hidden="true">&#9788;</span> Terang</button>
            <button type="button" class="theme-option" :class="{ 'theme-option-active': isDarkMode }" :aria-pressed="isDarkMode" @click="setTheme(true)"><span aria-hidden="true">&#9790;</span> Gelap</button>
          </div>
        </div>
      </section>

      <section class="rounded-2xl border overflow-hidden" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="px-6 py-5 border-b" :style="{ borderColor: 'var(--border)' }">
          <h2 class="text-base font-extrabold" :style="{ color: 'var(--foreground)' }">Tampilan hasil</h2>
          <p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Sesuaikan cara laporan analisis ditampilkan.</p>
        </div>
        <div class="px-6 py-5 flex items-center justify-between gap-5">
          <div>
            <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">Mode ringkas</p>
            <p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Gunakan tampilan daftar yang lebih padat pada halaman hasil.</p>
          </div>
          <button
            type="button"
            class="settings-switch"
            :class="{ 'settings-switch-on': compactResults }"
            :aria-pressed="compactResults"
            aria-label="Aktifkan mode ringkas"
            @click="compactResults = !compactResults"
          ><span /></button>
        </div>
      </section>

      <div class="flex justify-end pt-1">
        <button
          class="rounded-xl px-5 py-2.5 text-sm font-bold"
          :style="{ background: 'var(--primary)', color: 'var(--primary-foreground)' }"
          @click="saveSettings"
        >
          Simpan Pengaturan
        </button>
      </div>
    </div>
  </main>
</template>

<style scoped>
.saved-text {
  color: #15803d;
}
.dark .saved-text {
  color: #4ade80;
}

.settings-switch {
  display: inline-flex;
  width: 42px;
  height: 24px;
  flex-shrink: 0;
  align-items: center;
  padding: 3px;
  border-radius: 999px;
  background: #cbd5e1;
  transition: background 0.15s ease;
}
.dark .settings-switch {
  background: #475569;
}

.settings-switch span {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 3px rgba(15, 22, 41, 0.2);
  transition: transform 0.15s ease;
}

.settings-switch-on {
  justify-content: flex-end;
  background: var(--accent);
}

.theme-options {
  display: inline-flex;
  gap: 0.25rem;
  padding: 0.25rem;
  border: 1px solid var(--border);
  border-radius: 0.75rem;
  background: var(--muted);
}

.theme-option {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.75rem;
  border-radius: 0.55rem;
  color: var(--muted-foreground);
  font-size: 0.75rem;
  font-weight: 700;
  transition: color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.theme-option-active {
  background: var(--card);
  color: var(--foreground);
  box-shadow: 0 1px 3px rgba(15, 22, 41, 0.12);
}
</style>