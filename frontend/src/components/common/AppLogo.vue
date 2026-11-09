<script setup lang="ts">
import { computed } from "vue";
import citelensLogo from "@/assets/citelens_logo.png";

const props = withDefaults(defineProps<{ size?: "sm" | "md" | "lg" | "xl" }>(), {
  size: "md",
});

// Logo aslinya berukuran 1983×793px (rasio ~2.5:1) — kontrol lebarnya lewat height.
// md = ukuran default navbar/header. xl = ukuran hero login/register (besar).
const heightClass = computed(() => {
  switch (props.size) {
    case "sm":
      return "h-7";
    case "md":
      return "h-9";
    case "lg":
      return "h-14";
    case "xl":
      return "h-20";
    default:
      return "h-9";
  }
});

const sizeClass = computed(() => `brand-logo-${props.size}`);
</script>

<template>
  <div :class="['brand-logo', sizeClass]" role="img" aria-label="CiteLens">
    <span class="brand-logo-mark" aria-hidden="true">
      <img :src="citelensLogo" alt="" :class="[heightClass, 'w-auto select-none']" />
    </span>
    <span class="brand-logo-wordmark" aria-hidden="true">
      <span class="brand-logo-cite">Cite</span><span class="brand-logo-lens">Lens</span>
    </span>
  </div>
</template>