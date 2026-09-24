<script setup lang="ts">
import { RouterLink, useRoute } from "vue-router";
import AppLogo from "@/components/common/AppLogo.vue";
import UserProfileMenu from "@/components/common/UserProfileMenu.vue";

const route = useRoute();

const navItems = [
  { label: "Beranda", to: { name: "upload" }, matchNames: ["upload", "processing", "result"] },
  { label: "Riwayat", to: { name: "history" }, matchNames: ["history"] },
];

function isActive(matchNames: string[]) {
  return matchNames.includes(route.name as string);
}
</script>

<template>
  <header
    class="flex items-center justify-between px-6 py-3 border-b"
    :style="{ borderColor: 'var(--border)', background: 'var(--card)' }"
  >
    <div class="flex items-center gap-8">
      <AppLogo />

      <nav class="hidden sm:flex items-center gap-1">
        <RouterLink
          v-for="item in navItems"
          :key="item.label"
          :to="item.to"
          class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
          :style="{
            background: isActive(item.matchNames) ? 'var(--secondary)' : 'transparent',
            color: isActive(item.matchNames) ? 'var(--primary)' : 'var(--muted-foreground)',
          }"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
    </div>

    <UserProfileMenu />
  </header>
</template>