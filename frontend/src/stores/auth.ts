import { defineStore } from "pinia";
import { computed, ref } from "vue";
import type { AuthUser } from "@/types";

export const useAuthStore = defineStore("auth", () => {
  const user = ref<AuthUser | null>(null);
  const isAuthenticated = computed(() => user.value !== null);

  /** Mock login. Swap the setTimeout body for a real API call when the backend is ready. */
  function login(email: string, _password: string) {
    return new Promise<void>((resolve) => {
      setTimeout(() => {
        user.value = { name: "Andi Firmansyah", email };
        resolve();
      }, 1200);
    });
  }

  /** Mock register. Swap the setTimeout body for a real API call when the backend is ready. */
  function register(name: string, email: string, _password: string) {
    return new Promise<void>((resolve) => {
      setTimeout(() => {
        user.value = { name, email };
        resolve();
      }, 1400);
    });
  }

  function logout() {
    user.value = null;
  }

  return { user, isAuthenticated, login, register, logout };
});
