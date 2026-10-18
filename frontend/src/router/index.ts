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
      path: "/lupa-kata-sandi",
      name: "forgot-password",
      component: () => import("@/views/ForgotPasswordView.vue"),
      meta: { hideHeader: true, public: true },
    },
    {
      path: "/",
      name: "upload",
      component: () => import("@/views/UploadView.vue"),
    },
    {
      // :id = UUID dokumen. Dibuat opsional selama view lain masih memakai
      // data mock; jadikan wajib (hapus "?") setelah semua pemanggil diperbarui.
      path: "/processing/:id?",
      name: "processing",
      component: () => import("@/views/ProcessingView.vue"),
      props: true,
    },
    {
      path: "/hasil/:id?",
      name: "result",
      component: () => import("@/views/ResultView.vue"),
      props: true,
    },
    {
      path: "/riwayat",
      name: "history",
      component: () => import("@/views/HistoryView.vue"),
    },
    {
      path: "/profil",
      name: "profile",
      component: () => import("@/views/ProfileView.vue"),
    },
    {
      path: "/dokumen-saya",
      name: "documents",
      component: () => import("@/views/DocumentsView.vue"),
    },
    {
      path: "/pengaturan",
      name: "settings",
      component: () => import("@/views/SettingsView.vue"),
    },
    ...(import.meta.env.DEV
      ? [
          {
            path: "/dev/viewer",
            name: "viewer-demo",
            component: () => import("@/views/ViewerDemoView.vue"),
          },
        ]
      : []),
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