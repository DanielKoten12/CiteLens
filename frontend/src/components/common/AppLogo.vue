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
</script>

<template>
  <img :src="citelensLogo" alt="CiteLens" :class="[heightClass, 'w-auto select-none']" />
</template>