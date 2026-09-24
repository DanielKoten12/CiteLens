<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();

const open = ref(false);

const displayName = computed(() => auth.user?.name ?? "Andi Firmansyah");
const displayEmail = computed(() => auth.user?.email ?? "andi@mahasiswa.ac.id");
const initial = computed(() => displayName.value.charAt(0).toUpperCase());

function toggle() {
  open.value = !open.value;
}

function close() {
  open.value = false;
}

function handleLogout() {
  close();
  auth.logout();
  router.push({ name: "login" });
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener("click", close);
  } else {
    document.removeEventListener("click", close);
  }
});

onBeforeUnmount(() => document.removeEventListener("click", close));

const menuItems = [
  {
    label: "Profil Saya",
    icon: `<circle cx="6.5" cy="4.5" r="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M1.5 11.5c0-2.2 2.2-4 5-4s5 1.8 5 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>`,
  },
  {
    label: "Dokumen Saya",
    icon: `<rect x="1.5" y="1.5" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M4.5 6.5h4M6.5 4.5v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>`,
  },
  {
    label: "Pengaturan",
    icon: `<path d="M6.5 1.5v1M6.5 10.5v1M1.5 6.5h-1M11.5 6.5h1M3 3l.7.7M9.3 9.3l.7.7M3 10l.7-.7M9.3 3.7l.7-.7M9 6.5a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>`,
  },
];
</script>

<template>
  <div class="relative">
    <button
      class="flex items-center gap-2 px-2 py-1.5 rounded-xl transition-all duration-150"
      :style="{ background: open ? 'var(--secondary)' : 'transparent' }"
      @click.stop="toggle"
    >
      <div
        class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-extrabold flex-shrink-0"
        :style="{ background: 'var(--primary)', color: 'white' }"
      >
        {{ initial }}
      </div>
      <div class="hidden sm:block text-left">
        <p class="text-xs font-bold leading-none" :style="{ color: 'var(--foreground)' }">{{ displayName }}</p>
        <p class="text-xs leading-none mt-0.5" :style="{ color: 'var(--muted-foreground)' }">{{ displayEmail }}</p>
      </div>
      <svg
        width="12"
        height="12"
        fill="none"
        viewBox="0 0 12 12"
        class="hidden sm:block"
        :style="{ color: 'var(--muted-foreground)', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 0.2s' }"
      >
        <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full mt-2 rounded-xl border shadow-lg overflow-hidden z-50"
      :style="{ background: 'var(--card)', borderColor: 'var(--border)', minWidth: '200px' }"
      @click.stop
    >
      <div class="px-4 py-3 border-b" :style="{ borderColor: 'var(--border)' }">
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-extrabold flex-shrink-0"
            :style="{ background: 'var(--primary)', color: 'white' }"
          >
            {{ initial }}
          </div>
          <div>
            <p class="text-sm font-bold" :style="{ color: 'var(--foreground)' }">{{ displayName }}</p>
            <p class="text-xs" :style="{ color: 'var(--muted-foreground)' }">{{ displayEmail }}</p>
          </div>
        </div>
      </div>

      <button
        v-for="item in menuItems"
        :key="item.label"
        class="w-full flex items-center gap-3 px-4 py-2.5 text-xs font-medium transition-colors text-left hover:bg-[var(--muted)]"
        :style="{ color: 'var(--foreground)' }"
      >
        <span :style="{ color: 'var(--muted-foreground)' }">
          <svg width="13" height="13" fill="none" viewBox="0 0 13 13" v-html="item.icon" />
        </span>
        {{ item.label }}
      </button>

      <div class="h-px mx-3 my-1" :style="{ background: 'var(--border)' }" />

      <button
        class="w-full flex items-center gap-3 px-4 py-2.5 text-xs font-medium transition-colors text-left hover:bg-[#fff1f1]"
        style="color: #b91c1c"
        @click="handleLogout"
      >
        <svg width="13" height="13" fill="none" viewBox="0 0 13 13">
          <path d="M8.5 4.5l-4 4M4.5 4.5l4 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
          <path
            d="M5 1.5H3a1.5 1.5 0 00-1.5 1.5v7A1.5 1.5 0 003 11.5h2M8.5 9l2.5-2.5L8.5 4"
            stroke="currentColor"
            stroke-width="1.3"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
          <path d="M11 6.5H5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
        </svg>
        Keluar
      </button>
    </div>
  </div>
</template>
