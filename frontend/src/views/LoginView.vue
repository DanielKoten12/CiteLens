<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { CircleAlert, Check, LoaderCircle, X } from "lucide-vue-next";
import AppLogo from "@/components/common/AppLogo.vue";
import PasswordInput from "@/components/common/PasswordInput.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const form = reactive({ email: "", password: "" });
const error = ref("");
const loading = ref(false);

// Toast "akun berhasil dibuat"
const TOAST_DURATION = 4500; // ms tampil sebelum mulai menghilang
const showSuccess = ref(false);
let toastTimer: ReturnType<typeof setTimeout> | undefined;

function dismissToast() {
  clearTimeout(toastTimer);
  showSuccess.value = false;
}

onMounted(() => {
  if (route.query.registered !== "1") return;
  showSuccess.value = true;
  toastTimer = setTimeout(dismissToast, TOAST_DURATION);
  // Bersihkan query supaya refresh tidak menampilkan toast lagi.
  router.replace({ name: "login" });
});

onBeforeUnmount(() => clearTimeout(toastTimer));

async function handleSubmit() {
  error.value = "";
  if (!form.email || !form.password) {
    error.value = "Email dan kata sandi harus diisi.";
    return;
  }

  loading.value = true;
  try {
    await auth.login(form.email, form.password);
    router.push({ name: "upload" });
  } catch (e) {
    if (e instanceof ApiError) {
      if (e.status === 422) {
        // Kredensial salah dibalas 422 oleh backend.
        error.value = "Email atau kata sandi salah.";
      } else if (e.code === "RATE_LIMITED") {
        error.value = "Terlalu banyak percobaan. Coba lagi dalam satu menit.";
      } else {
        error.value = e.message;
      }
    } else {
      error.value = "Terjadi kesalahan tak terduga. Coba lagi.";
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div
    class="min-h-screen flex flex-col items-center justify-center px-4 py-12"
    :style="{ background: 'var(--background)' }"
  >
    <!-- Toast berhasil daftar -->
    <Transition name="toast">
      <div
        v-if="showSuccess"
        role="status"
        aria-live="polite"
        class="toast"
        :style="{ '--toast-duration': TOAST_DURATION + 'ms' }"
      >
        <div class="flex items-start gap-3 px-4 py-3.5">
          <span class="toast-icon">
            <Check class="h-3.5 w-3.5" :stroke-width="3" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold leading-5" :style="{ color: 'var(--foreground)' }">
              Akun berhasil dibuat
            </p>
            <p class="text-xs leading-5" :style="{ color: 'var(--muted-foreground)' }">
              Masuk dengan email dan kata sandi yang barusan kamu daftarkan.
            </p>
          </div>
          <button
            type="button"
            class="toast-close"
            aria-label="Tutup notifikasi"
            @click="dismissToast"
          >
            <X class="h-4 w-4" />
          </button>
        </div>
        <div class="toast-progress" />
      </div>
    </Transition>

    <RouterLink :to="{ name: 'login' }" class="mb-10">
      <AppLogo size="xl" />
    </RouterLink>

    <Card class="w-full rounded-2xl" style="max-width: 420px">
      <CardContent class="p-8">
        <h1 class="text-xl font-extrabold mb-1" :style="{ color: 'var(--foreground)' }">
          Masuk ke akun kamu
        </h1>
        <p class="text-sm mb-6" :style="{ color: 'var(--muted-foreground)' }">
          Belum punya akun?
          <RouterLink
            :to="{ name: 'register' }"
            class="font-semibold underline underline-offset-2"
            :style="{ color: 'var(--accent)' }"
          >
            Daftar sekarang
          </RouterLink>
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="handleSubmit">
          <div class="flex flex-col gap-1.5">
            <Label for="email">Email</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              placeholder="nama@email.com"
              autocomplete="email"
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
              <Label for="password">Kata Sandi</Label>
              <button
                type="button"
                class="text-xs underline underline-offset-2"
                :style="{ color: 'var(--muted-foreground)' }"
                @click="router.push({ name: 'forgot-password' })"
              >
                Lupa kata sandi?
              </button>
            </div>
            <PasswordInput
              id="password"
              v-model="form.password"
              placeholder="Masukkan kata sandi"
              autocomplete="current-password"
            />
          </div>

          <Alert v-if="error" variant="destructive">
            <CircleAlert />
            <AlertDescription>{{ error }}</AlertDescription>
          </Alert>

          <Button type="submit" size="lg" class="mt-1 rounded-xl" :disabled="loading">
            <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
            {{ loading ? "Memproses..." : "Masuk" }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>

<style scoped>
.toast {
  position: fixed;
  top: 1.25rem;
  left: 50%;
  translate: -50% 0;
  z-index: 50;
  width: min(92vw, 380px);
  overflow: hidden;
  border-radius: 0.875rem;
  border: 1px solid var(--border);
  background: var(--card);
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.04), 0 8px 24px -8px rgb(0 0 0 / 0.18);
}

.toast-icon {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.375rem;
  height: 1.375rem;
  margin-top: 0.0625rem;
  border-radius: 9999px;
  background: #22c55e;
  color: #fff;
}

.toast-close {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 0.375rem;
  color: var(--muted-foreground);
  transition: background-color 0.15s, color 0.15s;
}
.toast-close:hover {
  background: var(--muted);
  color: var(--foreground);
}

/* Garis sisa waktu */
.toast-progress {
  height: 2px;
  background: #22c55e;
  transform-origin: left;
  animation: toast-progress var(--toast-duration) linear forwards;
}
@keyframes toast-progress {
  from { transform: scaleX(1); }
  to { transform: scaleX(0); }
}

/* Muncul cepat, hilang pelan */
.toast-enter-active {
  transition: opacity 0.35s ease-out, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.toast-leave-active {
  transition: opacity 1.1s ease-in-out, transform 1.1s ease-in-out;
}
.toast-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}
.toast-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
  .toast-enter-active,
  .toast-leave-active {
    transition: opacity 0.2s linear;
  }
  .toast-enter-from,
  .toast-leave-to {
    transform: none;
  }
  .toast-progress {
    animation: none;
    display: none;
  }
}
</style>