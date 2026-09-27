<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();

const name = ref(auth.user?.name ?? "Andi Firmansyah");
const email = computed(() => auth.user?.email ?? "andi@mahasiswa.ac.id");
const institution = ref("Universitas Indonesia");
const studyProgram = ref("Teknik Informatika");
const isSaved = ref(false);
const initial = computed(() => name.value.charAt(0).toUpperCase() || "A");

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
  <main class="max-w-5xl mx-auto w-full px-6 py-10">
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
          <div class="w-20 h-20 rounded-full flex items-center justify-center text-2xl font-extrabold" :style="{ background: 'var(--primary)', color: 'white' }">{{ initial }}</div>
          <h2 class="text-lg font-extrabold mt-4" :style="{ color: 'var(--foreground)' }">{{ name }}</h2>
          <p class="text-sm mt-1 break-all" :style="{ color: 'var(--muted-foreground)' }">{{ email }}</p>
          <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full mt-5" style="background: #dcfce7; color: #15803d">
            <span class="w-1.5 h-1.5 rounded-full" style="background: #16a34a" /> Akun aktif
          </span>
        </div>
        <div class="h-px my-6" :style="{ background: 'var(--border)' }" />
        <div class="grid grid-cols-2 gap-3 text-center">
          <div class="rounded-xl p-3" :style="{ background: 'var(--muted)' }"><p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">5</p><p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">Dokumen</p></div>
          <div class="rounded-xl p-3" :style="{ background: 'var(--muted)' }"><p class="text-lg font-extrabold" :style="{ color: 'var(--foreground)' }">82%</p><p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">Rata-rata skor</p></div>
        </div>
      </section>

      <section class="rounded-2xl border p-6 lg:col-span-2" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
        <div class="flex items-start justify-between gap-4 mb-6">
          <div><h2 class="text-base font-extrabold" :style="{ color: 'var(--foreground)' }">Informasi akun</h2><p class="text-xs mt-1" :style="{ color: 'var(--muted-foreground)' }">Perbarui detail profil yang tampil di aplikasi.</p></div>
          <span v-if="isSaved" class="text-xs font-bold" style="color: #15803d">Tersimpan</span>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="saveProfile">
          <label class="flex flex-col gap-2"><span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Nama lengkap</span><input v-model="name" class="h-11 rounded-xl border px-3.5 text-sm focus:outline-none" :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }" /></label>
          <label class="flex flex-col gap-2"><span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Email</span><input :value="email" disabled class="h-11 rounded-xl border px-3.5 text-sm opacity-70" :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--muted)' }" /></label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="flex flex-col gap-2"><span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Institusi</span><input v-model="institution" class="h-11 rounded-xl border px-3.5 text-sm focus:outline-none" :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }" /></label>
            <label class="flex flex-col gap-2"><span class="text-xs font-bold" :style="{ color: 'var(--foreground)' }">Program studi</span><input v-model="studyProgram" class="h-11 rounded-xl border px-3.5 text-sm focus:outline-none" :style="{ borderColor: 'var(--border)', color: 'var(--foreground)', background: 'var(--card)' }" /></label>
          </div>
          <div class="flex justify-end pt-2"><button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-bold text-white" :style="{ background: 'var(--primary)' }">Simpan Perubahan</button></div>
        </form>
      </section>
    </div>
  </main>
</template>
