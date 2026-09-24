import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores/auth";

declare module "vue-router" {
  interface RouteMeta {
    /** Hide the global app header on this route (auth pages are full-bleed). */
    hideHeader?: boolean;
    /** Reachable without being logged in. */
    public?: boolean;
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: "/login",
      name: "login",
      component: () => import("@/views/LoginView.vue"),
      meta: { hideHeader: true, public: true },
    },
    {
      path: "/register",
      name: "register",
      component: () => import("@/views/RegisterView.vue"),
      meta: { hideHeader: true, public: true },
    },
    {
      path: "/",
      name: "upload",
      component: () => import("@/views/UploadView.vue"),
    },
    {
      path: "/processing",
      name: "processing",
      component: () => import("@/views/ProcessingView.vue"),
    },
    {
      path: "/hasil",
      name: "result",
      component: () => import("@/views/ResultView.vue"),
    },
    {
      path: "/riwayat",
      name: "history",
      component: () => import("@/views/HistoryView.vue"),
    },
  ],
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach((to) => {
  const auth = useAuthStore();
  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: "login" };
  }
  if (to.meta.public && auth.isAuthenticated) {
    return { name: "upload" };
  }
});

export default router;
