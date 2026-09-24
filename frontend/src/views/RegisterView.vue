<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { CircleAlert, CircleCheck, LoaderCircle } from "lucide-vue-next";
import AppLogo from "@/components/common/AppLogo.vue";
import PasswordInput from "@/components/common/PasswordInput.vue";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();

const form = reactive({ name: "", email: "", password: "", confirmPassword: "" });
const errors = ref<Record<string, string>>({});
const loading = ref(false);

function validate() {
  const e: Record<string, string> = {};
  if (!form.name.trim()) e.name = "Nama lengkap harus diisi.";
  if (!form.email) e.email = "Email harus diisi.";
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) e.email = "Format email tidak valid.";
  if (!form.password) e.password = "Kata sandi harus diisi.";
  else if (form.password.length < 8) e.password = "Kata sandi minimal 8 karakter.";
  if (!form.confirmPassword) e.confirmPassword = "Konfirmasi kata sandi harus diisi.";
  else if (form.password !== form.confirmPassword) e.confirmPassword = "Kata sandi tidak cocok.";
  return e;
}

const strength = computed(() => {
  if (!form.password) return 0;
  let s = 0;
  if (form.password.length >= 8) s++;
  if (/[A-Z]/.test(form.password)) s++;
  if (/[0-9]/.test(form.password)) s++;
  if (/[^A-Za-z0-9]/.test(form.password)) s++;
  return s;
});

const strengthLabels = ["", "Lemah", "Cukup", "Kuat", "Sangat Kuat"];
const strengthColors = ["", "#ef4444", "#f59e0b", "#3b82f6", "#22c55e"];
const strengthLabel = computed(() => strengthLabels[strength.value]);
const strengthColor = computed(() => strengthColors[strength.value]);

async function handleSubmit() {
  const errs = validate();
  errors.value = errs;
  if (Object.keys(errs).length > 0) return;
  loading.value = true;
  await auth.register(form.name, form.email, form.password);
  loading.value = false;
  router.push({ name: "upload" });
}
</script>

<template>
  <div
    class="min-h-screen flex flex-col items-center justify-center px-4 py-12"
    :style="{ background: 'var(--background)' }"
  >
    <RouterLink :to="{ name: 'login' }" class="mb-10">
      <AppLogo size="xl" />
    </RouterLink>

    <Card class="w-full rounded-2xl" style="max-width: 420px">
      <CardContent class="p-8">
        <h1 class="text-xl font-extrabold mb-1" :style="{ color: 'var(--foreground)' }">
          Buat akun baru
        </h1>
        <p class="text-sm mb-6" :style="{ color: 'var(--muted-foreground)' }">
          Sudah punya akun?
          <RouterLink
            :to="{ name: 'login' }"
            class="font-semibold underline underline-offset-2"
            :style="{ color: 'var(--accent)' }"
          >
            Login
          </RouterLink>
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="handleSubmit">
          <!-- Nama -->
          <div class="flex flex-col gap-1.5">
            <Label for="name">Nama Lengkap</Label>
            <Input
              id="name"
              v-model="form.name"
              placeholder="Nama kamu"
              autocomplete="name"
              :class="cn(errors.name && 'border-destructive focus-visible:ring-destructive')"
            />
            <p v-if="errors.name" class="text-xs flex items-center gap-1" style="color: #ef4444">
              <CircleAlert class="h-3 w-3" />
              {{ errors.name }}
            </p>
          </div>

          <!-- Email -->
          <div class="flex flex-col gap-1.5">
            <Label for="email">Email</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              placeholder="nama@email.com"
              autocomplete="email"
              :class="cn(errors.email && 'border-destructive focus-visible:ring-destructive')"
            />
            <p v-if="errors.email" class="text-xs flex items-center gap-1" style="color: #ef4444">
              <CircleAlert class="h-3 w-3" />
              {{ errors.email }}
            </p>
          </div>

          <!-- Kata Sandi + kekuatan -->
          <div class="flex flex-col gap-1.5">
            <Label for="password">Kata Sandi</Label>
            <PasswordInput
              id="password"
              v-model="form.password"
              placeholder="Min. 8 karakter"
              autocomplete="new-password"
              :error="!!errors.password"
            />

            <div v-if="form.password" class="flex items-center gap-2 mt-0.5">
              <div class="flex gap-1 flex-1">
                <div
                  v-for="i in 4"
                  :key="i"
                  class="h-1 flex-1 rounded-full transition-all duration-300"
                  :style="{ background: i <= strength ? strengthColor : 'var(--muted)' }"
                />
              </div>
              <span class="text-xs font-semibold" :style="{ color: strengthColor }">{{ strengthLabel }}</span>
            </div>

            <p v-if="errors.password" class="text-xs flex items-center gap-1" style="color: #ef4444">
              <CircleAlert class="h-3 w-3" />
              {{ errors.password }}
            </p>
          </div>

          <!-- Konfirmasi Kata Sandi -->
          <div class="flex flex-col gap-1.5">
            <Label for="confirmPassword">Konfirmasi Kata Sandi</Label>
            <div class="relative">
              <Input
                id="confirmPassword"
                v-model="form.confirmPassword"
                type="password"
                placeholder="Ulangi kata sandi"
                autocomplete="new-password"
                :class="cn('pr-10', errors.confirmPassword && 'border-destructive focus-visible:ring-destructive')"
              />
              <CircleCheck
                v-if="form.confirmPassword && form.password === form.confirmPassword"
                class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2"
                style="color: #22c55e"
              />
            </div>
            <p v-if="errors.confirmPassword" class="text-xs flex items-center gap-1" style="color: #ef4444">
              <CircleAlert class="h-3 w-3" />
              {{ errors.confirmPassword }}
            </p>
          </div>

          <Button type="submit" size="lg" class="mt-1 rounded-xl" :disabled="loading">
            <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
            {{ loading ? "Membuat akun..." : "Buat Akun" }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </div>
</template>