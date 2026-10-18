import { defineStore } from "pinia";
import { computed, ref } from "vue";
import type { AuthUser } from "@/types";
import { api, tokenStorage } from "@/lib/api";

interface AuthResponse {
  data: { user: AuthUser; token: string; token_type: string };
}

export const useAuthStore = defineStore("auth", () => {
  const user = ref<AuthUser | null>(null);
  const isAuthenticated = computed(() => user.value !== null);

  function applyAuth(res: AuthResponse) {
    tokenStorage.set(res.data.token);
    user.value = res.data.user;
  }

  /** POST /auth/login. Gagal -> ApiError (422 untuk kredensial salah, 429 rate limit). */
  async function login(email: string, password: string) {
    applyAuth(
      await api<AuthResponse>("/auth/login", {
        method: "POST",
        body: JSON.stringify({ email, password }),
      }),
    );
  }

  /** POST /auth/register. Backend mewajibkan password_confirmation. */
  async function register(
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string = password,
  ) {
    applyAuth(
      await api<AuthResponse>("/auth/register", {
        method: "POST",
        body: JSON.stringify({
          name,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      }),
    );
  }

  /** Pulihkan sesi dari token tersimpan. Panggil sekali sebelum router dipasang. */
  async function init() {
    if (!tokenStorage.get()) return;
    try {
      user.value = (await api<{ data: AuthUser }>("/auth/me")).data;
    } catch {
      clearSession();
    }
  }

  async function logout() {
    try {
      await api("/auth/logout", { method: "POST" });
    } catch {
      /* tetap logout di sisi klien */
    }
    clearSession();
  }

  function clearSession() {
    tokenStorage.clear();
    user.value = null;
  }

  return { user, isAuthenticated, login, register, logout, init, clearSession };
});