<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import type { PipelineStep } from "@/types";

const router = useRouter();

const steps: PipelineStep[] = [
  { num: "01", title: "Mengekstrak teks dokumen", desc: "" },
  { num: "02", title: "Mendeteksi sitasi in-text", desc: "" },
  { num: "03", title: "Memvalidasi ke Crossref & OpenAlex", desc: "" },
  { num: "04", title: "Menyusun laporan analisis", desc: "" },
];

const TOTAL_DURATION_MS = 5600;

const percent = ref(0);
const startedAt = ref(0);
let frameId: number | null = null;

const currentStepIndex = computed(() => {
  const ratio = percent.value / 100;
  return Math.min(steps.length - 1, Math.floor(ratio * steps.length));
});

const RING_RADIUS = 54;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - percent.value / 100));

function tick(timestamp: number) {
  if (!startedAt.value) startedAt.value = timestamp;
  const elapsed = timestamp - startedAt.value;
  const ratio = Math.min(1, elapsed / TOTAL_DURATION_MS);
  percent.value = Math.round(ratio * 100);

  if (ratio < 1) {
    frameId = requestAnimationFrame(tick);
  } else {
    setTimeout(() => router.push({ name: "result" }), 400);
  }
}

onMounted(() => {
  frameId = requestAnimationFrame(tick);
});

onBeforeUnmount(() => {
  if (frameId) cancelAnimationFrame(frameId);
});

function stepState(index: number): "done" | "active" | "pending" {
  if (percent.value >= 100) return "done";
  if (index < currentStepIndex.value) return "done";
  if (index === currentStepIndex.value) return "active";
  return "pending";
}
</script>

<template>
  <div class="max-w-lg mx-auto px-6 py-16">
    <div class="rounded-2xl border p-10 flex flex-col items-center" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <svg width="130" height="130" viewBox="0 0 130 130">
        <circle cx="65" cy="65" :r="RING_RADIUS" fill="none" stroke="var(--secondary)" stroke-width="9" />
        <circle
          cx="65"
          cy="65"
          :r="RING_RADIUS"
          fill="none"
          stroke="var(--accent)"
          stroke-width="9"
          stroke-linecap="round"
          :stroke-dasharray="RING_CIRCUMFERENCE"
          :stroke-dashoffset="ringOffset"
          transform="rotate(-90 65 65)"
          style="transition: stroke-dashoffset 0.1s linear"
        />
        <text x="65" y="72" text-anchor="middle" font-size="22" font-weight="800" fill="var(--foreground)">
          {{ percent }}%
        </text>
      </svg>

      <h1 class="text-xl font-extrabold mt-6" :style="{ color: 'var(--foreground)' }">Sedang Memproses Dokumen</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--muted-foreground)' }">Harap tunggu, analisis sedang berjalan&hellip;</p>

      <ul class="w-full flex flex-col gap-2 mt-8">
        <li
          v-for="(step, index) in steps"
          :key="step.num"
          class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors duration-300"
          :style="{ background: stepState(index) === 'active' ? 'var(--secondary)' : 'transparent' }"
        >
          <span
            class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 transition-colors duration-300"
            :style="{
              background:
                stepState(index) === 'done' ? '#4ade80' : stepState(index) === 'active' ? 'var(--accent)' : 'var(--border)',
            }"
          >
            <Transition name="pop" mode="out-in">
              <svg v-if="stepState(index) === 'done'" key="check" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M2.5 6.2l2.3 2.3L9.5 3.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              <span
                v-else
                key="dot"
                class="w-2 h-2 rounded-full"
                :style="{ background: stepState(index) === 'active' ? 'white' : 'var(--muted-foreground)' }"
              />
            </Transition>
          </span>

          <span
            class="text-sm transition-colors duration-300"
            :style="{
              color: stepState(index) === 'active' ? 'var(--foreground)' : 'var(--muted-foreground)',
              fontWeight: stepState(index) === 'active' ? 700 : 500,
            }"
          >
            {{ step.title }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.pop-enter-active {
  transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease;
}
.pop-leave-active {
  transition: transform 0.15s ease, opacity 0.15s ease;
}
.pop-enter-from {
  transform: scale(0.3);
  opacity: 0;
}
.pop-leave-to {
  transform: scale(0.5);
  opacity: 0;
}
</style>