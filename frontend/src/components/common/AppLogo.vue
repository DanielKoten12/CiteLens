<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import citelensLogo from "@/assets/citelens_logo.png";
import citelensLogoDark from "@/assets/citelens_logo2.png";

const props = withDefaults(defineProps<{ size?: "sm" | "md" | "lg" | "xl" }>(), {
  size: "md",
});

// Logo aslinya berukuran 1983×793px (rasio ~2.5:1), jadi lebar dikontrol lewat height.
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

// Ikuti class "dark" di <html> secara reaktif, termasuk saat tema diganti di Pengaturan.
const isDark = ref(document.documentElement.classList.contains("dark"));
let observer: MutationObserver | undefined;

onMounted(() => {
  isDark.value = document.documentElement.classList.contains("dark");
  observer = new MutationObserver(() => {
    isDark.value = document.documentElement.classList.contains("dark");
  });
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
  <img
    :src="isDark ? citelensLogoDark : citelensLogo"
    alt="CiteLens"
    :class="[heightClass, 'w-auto select-none']"
  />
</template>