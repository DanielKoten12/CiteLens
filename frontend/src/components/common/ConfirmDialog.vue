<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import { LoaderCircle, Trash2 } from "lucide-vue-next";

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    description?: string;
    /** Nama item yang akan dihapus, ditampilkan dalam kotak kecil. */
    itemName?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    loading?: boolean;
  }>(),
  {
    description: "",
    itemName: "",
    confirmLabel: "Hapus",
    cancelLabel: "Batal",
    loading: false,
  },
);

const emit = defineEmits<{ (e: "confirm"): void; (e: "cancel"): void }>();

const panel = ref<HTMLElement | null>(null);
const cancelBtn = ref<HTMLButtonElement | null>(null);
let previouslyFocused: HTMLElement | null = null;

function onCancel() {
  if (props.loading) return;
  emit("cancel");
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === "Escape") {
    e.stopPropagation();
    onCancel();
    return;
  }
  // Fokus tidak keluar dari dialog.
  if (e.key === "Tab" && panel.value) {
    const items = Array.from(panel.value.querySelectorAll<HTMLElement>("button:not([disabled])"));
    if (items.length === 0) return;
    const first = items[0]!;
    const last = items[items.length - 1]!;
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }
}

watch(
  () => props.open,
  async (isOpen) => {
    if (isOpen) {
      previouslyFocused = document.activeElement as HTMLElement | null;
      document.body.style.overflow = "hidden";
      window.addEventListener("keydown", onKeydown);
      await nextTick();
      cancelBtn.value?.focus();
    } else {
      document.body.style.overflow = "";
      window.removeEventListener("keydown", onKeydown);
      previouslyFocused?.focus?.();
    }
  },
);

onBeforeUnmount(() => {
  document.body.style.overflow = "";
  window.removeEventListener("keydown", onKeydown);
});
</script>

<template>
  <Teleport to="body">
    <Transition name="dialog">
      <div v-if="open" class="dialog-root">
        <div class="dialog-backdrop" @click="onCancel" />

        <div
          ref="panel"
          class="dialog-panel"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="confirm-title"
          :aria-describedby="description ? 'confirm-desc' : undefined"
        >
          <div class="dialog-body">
            <span class="dialog-icon" aria-hidden="true">
              <Trash2 class="h-[18px] w-[18px]" />
            </span>

            <div class="min-w-0 flex-1">
              <h2 id="confirm-title" class="dialog-title">{{ title }}</h2>
              <p v-if="description" id="confirm-desc" class="dialog-desc">{{ description }}</p>
              <p v-if="itemName" class="dialog-item" :title="itemName">{{ itemName }}</p>
            </div>
          </div>

          <div class="dialog-actions">
            <button
              ref="cancelBtn"
              type="button"
              class="dialog-btn dialog-btn-cancel"
              :disabled="loading"
              @click="onCancel"
            >
              {{ cancelLabel }}
            </button>
            <button
              type="button"
              class="dialog-btn dialog-btn-danger"
              :disabled="loading"
              @click="emit('confirm')"
            >
              <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
              {{ loading ? "Menghapus..." : confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.dialog-root {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: flex-end; /* HP: bottom sheet */
  justify-content: center;
}

.dialog-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 22, 41, 0.45);
  backdrop-filter: blur(2px);
}
.dark .dialog-backdrop {
  background: rgba(0, 0, 0, 0.62);
}

.dialog-panel {
  position: relative;
  width: 100%;
  padding: 1.5rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom, 0px));
  border: 1px solid var(--border);
  border-bottom: 0;
  border-radius: 1.25rem 1.25rem 0 0;
  background: var(--card);
  box-shadow: 0 -8px 32px -8px rgba(15, 22, 41, 0.25);
}

.dialog-body {
  display: flex;
  align-items: flex-start;
  gap: 0.875rem;
}

.dialog-icon {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  background: #fee2e2;
  color: #b91c1c;
}
.dark .dialog-icon {
  background: rgba(239, 68, 68, 0.16);
  color: #fca5a5;
}

.dialog-title {
  font-size: 1rem;
  line-height: 1.4;
  font-weight: 800;
  color: var(--foreground);
}

.dialog-desc {
  margin-top: 0.25rem;
  font-size: 0.8125rem;
  line-height: 1.55;
  color: var(--muted-foreground);
}

.dialog-item {
  margin-top: 0.75rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 0.625rem;
  background: var(--muted);
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dialog-actions {
  display: flex;
  flex-direction: column-reverse; /* HP: tombol hapus di atas, penuh lebar */
  gap: 0.5rem;
  margin-top: 1.5rem;
}

.dialog-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 2.75rem;
  padding: 0 1.125rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  font-weight: 700;
  transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}
.dialog-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.dialog-btn:focus-visible {
  outline: 2px solid var(--ring);
  outline-offset: 2px;
}

.dialog-btn-cancel {
  border: 1px solid var(--border);
  background: var(--card);
  color: var(--foreground);
}
.dialog-btn-cancel:hover:not(:disabled) {
  background: var(--muted);
}

.dialog-btn-danger {
  border: 1px solid transparent;
  background: #dc2626;
  color: #fff;
}
.dialog-btn-danger:hover:not(:disabled) {
  background: #b91c1c;
}

/* ---------- Tablet / desktop: kotak di tengah ---------- */
@media (min-width: 640px) {
  .dialog-root {
    align-items: center;
    padding: 1.5rem;
  }
  .dialog-panel {
    max-width: 26rem;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border);
    border-radius: 1rem;
    box-shadow: 0 1px 2px rgba(15, 22, 41, 0.06), 0 24px 48px -12px rgba(15, 22, 41, 0.3);
  }
  .dialog-actions {
    flex-direction: row;
    justify-content: flex-end;
  }
  .dialog-btn {
    min-height: 2.5rem;
  }
}

/* ---------- Animasi ---------- */
.dialog-enter-active,
.dialog-leave-active {
  transition: opacity 0.2s ease;
}
.dialog-enter-active .dialog-panel {
  transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}
.dialog-leave-active .dialog-panel {
  transition: transform 0.18s ease-in;
}
.dialog-enter-from,
.dialog-leave-to {
  opacity: 0;
}
.dialog-enter-from .dialog-panel,
.dialog-leave-to .dialog-panel {
  transform: translateY(100%);
}
@media (min-width: 640px) {
  .dialog-enter-from .dialog-panel,
  .dialog-leave-to .dialog-panel {
    transform: translateY(8px) scale(0.98);
  }
}

@media (prefers-reduced-motion: reduce) {
  .dialog-enter-active .dialog-panel,
  .dialog-leave-active .dialog-panel {
    transition: none;
  }
  .dialog-enter-from .dialog-panel,
  .dialog-leave-to .dialog-panel {
    transform: none;
  }
}
</style>