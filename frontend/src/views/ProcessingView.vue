<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import { useDocumentPolling } from "@/composables/useDocumentPolling";
import type { AnalysisStep, DocumentStatus, DocumentStatusData } from "@/types/api";
import type { PipelineStep } from "@/types";

// id dokumen datang dari route: /processing/:id (props: true di router).
const props = defineProps<{ id?: string }>();
const router = useRouter();

const steps: PipelineStep[] = [
  { num: "01", title: "Mengekstrak referensi & sitasi", desc: "" },
  { num: "02", title: "Memvalidasi Daftar Pustaka", desc: "" },
  { num: "03", title: "Menghitung skor kecocokan", desc: "" },
  { num: "04", title: "Menautkan sitasi & menyusun hasil", desc: "" },
];

/** Langkah pipeline backend -> indeks langkah di UI. */
const STEP_INDEX: Record<AnalysisStep, number> = {
  queued: 0,
  extracting: 0,
  persisting: 0,
  crossref_validation: 1,
  embedding: 2,
  scoring: 2,
  resolving_citations: 3,
  generating_report: 3,
  completed: 3,
};

const status = ref<DocumentStatus>("pending");
const currentStep = ref<AnalysisStep>("queued");
const percent = ref(0);
const analysisError = ref("");
const fatalError = ref("");
const retryError = ref("");
const retrying = ref(false);

let redirectTimer: ReturnType<typeof setTimeout> | undefined;

const polling = props.id
  ? useDocumentPolling(props.id, onUpdate, (e: ApiError) => {
      fatalError.value =
        e.status === 404 ? "Dokumen tidak ditemukan." : "Kamu tidak punya akses ke dokumen ini.";
    })
  : null;

function onUpdate(data: DocumentStatusData) {
  status.value = data.status;
  currentStep.value = data.current_step;
  percent.value = data.status === "completed" ? 100 : Math.round(data.progress);
  analysisError.value = data.error ?? "";

  if (data.status === "completed" && props.id) {
    const id = props.id;
    redirectTimer = setTimeout(() => router.push({ name: "result", params: { id } }), 600);
  }
}

const isFailed = computed(() => status.value === "failed" || !!fatalError.value);
const currentStepIndex = computed(() => STEP_INDEX[currentStep.value] ?? 0);

const failureText = computed(
  () => fatalError.value || analysisError.value || "Analisis gagal. Silakan coba lagi.",
);

const headline = computed(() => (isFailed.value ? "Analisis Gagal" : "Sedang Memproses Dokumen"));
const subline = computed(() =>
  isFailed.value ? "Dokumen tidak dapat diselesaikan." : "Harap tunggu, analisis sedang berjalan…",
);

const RING_RADIUS = 54;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;
const ringOffset = computed(() => RING_CIRCUMFERENCE * (1 - percent.value / 100));

function stepState(index: number): "done" | "active" | "pending" | "failed" {
  if (status.value === "completed") return "done";
  if (index < currentStepIndex.value) return "done";
  if (index === currentStepIndex.value) return isFailed.value ? "failed" : "active";
  return "pending";
}

async function retry() {
  if (!props.id || retrying.value) return;
  retrying.value = true;
  retryError.value = "";
  try {
    await documentsApi.retry(props.id);
    status.value = "pending";
    currentStep.value = "queued";
    percent.value = 0;
    analysisError.value = "";
    polling?.start();
  } catch (e) {
    retryError.value = e instanceof ApiError ? e.message : "Gagal mengulang analisis.";
  } finally {
    retrying.value = false;
  }
}

function backToUpload() {
  router.push({ name: "upload" });
}

onMounted(() => {
  if (!props.id) {
    router.replace({ name: "upload" });
    return;
  }
  polling?.start();
});

onBeforeUnmount(() => {
  if (redirectTimer) clearTimeout(redirectTimer);
});
</script>

<template>
  <div class="max-w-md mx-auto px-6 pt-8 pb-12">
    <div class="rounded-2xl border px-8 py-7 flex flex-col items-center" :style="{ borderColor: 'var(--border)', background: 'var(--card)' }">
      <svg width="104" height="104" viewBox="0 0 130 130">
        <circle cx="65" cy="65" :r="RING_RADIUS" fill="none" stroke="var(--secondary)" stroke-width="9" />
        <circle
          cx="65"
          cy="65"
          :r="RING_RADIUS"
          fill="none"
          :stroke="isFailed ? '#ef4444' : 'var(--accent)'"
          stroke-width="9"
          stroke-linecap="round"
          :stroke-dasharray="RING_CIRCUMFERENCE"
          :stroke-dashoffset="ringOffset"
          transform="rotate(-90 65 65)"
          style="transition: stroke-dashoffset 0.5s ease"
        />
        <text x="65" y="72" text-anchor="middle" font-size="22" font-weight="800" fill="var(--foreground)">
          {{ percent }}%
        </text>
      </svg>

      <h1 class="text-lg font-extrabold mt-4" :style="{ color: 'var(--foreground)' }">{{ headline }}</h1>
      <p class="text-sm mt-0.5" :style="{ color: 'var(--muted-foreground)' }">{{ subline }}</p>

      <ul class="w-full flex flex-col gap-1.5 mt-5">
        <li
          v-for="(step, index) in steps"
          :key="step.num"
          class="step-row flex items-center gap-3 px-4 py-2.5 rounded-xl transition-colors duration-300"
          :class="{
            'step-row-active': stepState(index) === 'active',
            'step-row-failed': stepState(index) === 'failed',
          }"
        >
          <span
            class="step-dot w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 transition-colors duration-300"
            :class="`step-dot-${stepState(index)}`"
          >
            <Transition name="pop" mode="out-in">
              <svg v-if="stepState(index) === 'done'" key="check" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M2.5 6.2l2.3 2.3L9.5 3.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              <svg v-else-if="stepState(index) === 'failed'" key="fail" width="12" height="12" viewBox="0 0 12 12" fill="none">
                <path d="M3 3l6 6M9 3l-6 6" stroke="white" stroke-width="1.8" stroke-linecap="round" />
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
              color:
                stepState(index) === 'active' || stepState(index) === 'failed'
                  ? 'var(--foreground)'
                  : 'var(--muted-foreground)',
              fontWeight: stepState(index) === 'active' || stepState(index) === 'failed' ? 700 : 500,
            }"
          >
            {{ step.title }}
          </span>
        </li>
      </ul>

      <div v-if="isFailed" class="w-full mt-4 flex flex-col items-center gap-3">
        <div class="failure-box" role="alert">
          <svg class="failure-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4" />
            <path d="M8 4.8v3.8M8 11v.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
          <div class="min-w-0">
            <p class="failure-text">{{ failureText }}</p>
            <p v-if="retryError" class="failure-text mt-1">{{ retryError }}</p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button
            v-if="!fatalError"
            class="text-sm font-bold px-4 py-2 rounded-lg disabled:opacity-60"
            :style="{ background: 'var(--accent)', color: 'var(--accent-foreground)' }"
            :disabled="retrying"
            @click="retry"
          >
            {{ retrying ? "Memulai ulang..." : "Coba lagi" }}
          </button>
          <button
            class="text-sm font-bold px-4 py-2 rounded-lg border"
            :style="{ borderColor: 'var(--border)', color: 'var(--foreground)' }"
            @click="backToUpload"
          >
            Unggah file lain
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ---------- Baris langkah ---------- */
.step-row {
  background: transparent;
}
.step-row-active {
  background: var(--secondary);
}
.step-row-failed {
  background: #fee2e2;
}
.dark .step-row-failed {
  background: rgba(239, 68, 68, 0.14);
}

/* ---------- Bulatan status ---------- */
.step-dot-done {
  background: #22c55e;
}
.step-dot-active {
  background: var(--accent);
}
.step-dot-failed {
  background: #ef4444;
}
.step-dot-pending {
  background: var(--border);
}
/* Di mode gelap --border hampir sama dengan card, jadi bulatan "menunggu" dibuat sedikit lebih terang. */
.dark .step-dot-pending {
  background: #2a3a5c;
}

/* ---------- Kotak error ---------- */
.failure-box {
  display: flex;
  align-items: flex-start;
  gap: 0.625rem;
  width: 100%;
  padding: 0.75rem 0.875rem;
  border: 1px solid #fecaca;
  border-radius: 0.75rem;
  background: #fef2f2;
  color: #b91c1c;
}
.dark .failure-box {
  border-color: rgba(248, 113, 113, 0.3);
  background: rgba(239, 68, 68, 0.1);
  color: #fca5a5;
}

.failure-icon {
  flex-shrink: 0;
  margin-top: 1px;
}

.failure-text {
  font-size: 0.875rem;
  line-height: 1.4;
  font-weight: 600;
}

/* ---------- Animasi centang ---------- */
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