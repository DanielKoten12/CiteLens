<script setup lang="ts">
import { reactive, ref } from "vue";
import { useRouter } from "vue-router";
import { CircleAlert, LoaderCircle } from "lucide-vue-next";
import AppLogo from "@/components/common/AppLogo.vue";
import PasswordInput from "@/components/common/PasswordInput.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const auth = useAuthStore();

const form = reactive({ email: "", password: "" });
const error = ref("");
const loading = ref(false);

async function handleSubmit() {
  error.value = "";
  if (!form.email || !form.password) {
    error.value = "Email dan kata sandi harus diisi.";
    return;
  }
  loading.value = true;
  await auth.login(form.email, form.password);
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