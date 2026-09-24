<script setup lang="ts">
import { useRoute } from "vue-router";
import AppHeader from "@/components/layout/AppHeader.vue";

const route = useRoute();
</script>

<template>
  <div class="min-h-full flex flex-col" :style="{ background: 'var(--background)', color: 'var(--foreground)' }">
    <AppHeader v-if="!route.meta.hideHeader" />
    <RouterView />
  </div>
</template>
