<script setup lang="ts">
import { ref } from "vue";
import { CircleCheck, CircleAlert, LoaderCircle, Mail } from "lucide-vue-next";
import { useRouter } from "vue-router";
import AppLogo from "@/components/common/AppLogo.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const router = useRouter();
const email = ref("");
const error = ref("");
const submitted = ref(false);
const loading = ref(false);

async function handleSubmit() {
  error.value = "";
  submitted.value = false;

  if (!email.value.trim()) {
    error.value = "Email harus diisi.";
    return;
  }

  if (!/^\S+@\S+\.\S+$/.test(email.value)) {
    error.value = "Masukkan alamat email yang valid.";
    return;
  }

  loading.value = true;
  await new Promise((resolve) => window.setTimeout(resolve, 700));
  loading.value = false;
  submitted.value = true;
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
          Lupa Kata Sandi?
        </h1>
        <p class="text-sm mb-6" :style="{ color: 'var(--muted-foreground)' }">
          Masukkan email kamu untuk menerima tautan reset kata sandi.
        </p>

        <Alert v-if="submitted" class="mb-5" variant="default">
          <CircleCheck />
          <AlertDescription>
            Kami sudah mengirim tautan reset kata sandi ke email {{ email }}.
          </AlertDescription>
        </Alert>

        <Alert v-if="error" class="mb-5" variant="destructive">
          <CircleAlert />
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <form class="flex flex-col gap-4" @submit.prevent="handleSubmit">
          <div class="flex flex-col gap-1.5">
            <Label for="reset-email">Email</Label>
            <div class="relative">
              <Mail class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" :style="{ color: 'var(--muted-foreground)' }" />
              <Input
                id="reset-email"
                v-model="email"
                class="pl-10"
                type="email"
                placeholder="nama@email.com"
                autocomplete="email"
              />
            </div>
          </div>

          <Button type="submit" size="lg" class="mt-1 rounded-xl" :disabled="loading">
            <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
            {{ loading ? "Mengirim..." : "Kirim Tautan Reset" }}
          </Button>
        </form>

        <button
          type="button"
          class="w-full text-sm font-semibold mt-6"
          :style="{ color: 'var(--accent)' }"
          @click="router.push({ name: 'login' })"
        >
          <span aria-hidden="true">&larr;</span> Kembali ke halaman login
        </button>
      </CardContent>
    </Card>
  </div>
</template>
