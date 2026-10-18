import "./assets/main.css";

import { createApp } from "vue";
import { createPinia } from "pinia";

import App from "./App.vue";
import router from "./router";
import { setUnauthorizedHandler } from "./lib/api";
import { useAuthStore } from "./stores/auth";

if (localStorage.getItem("citelens-theme") === "dark") {
	document.documentElement.classList.add("dark");
}

async function bootstrap() {
	const app = createApp(App);
	app.use(createPinia());

	// Pulihkan sesi dari token tersimpan SEBELUM router dipasang,
	// supaya route guard tidak melempar pengguna ke halaman login saat refresh.
	const auth = useAuthStore();
	await auth.init();

	// Token kedaluwarsa/dicabut (API membalas 401): bersihkan sesi lalu ke login.
	setUnauthorizedHandler(() => {
		auth.clearSession();
		router.push({ name: "login" });
	});

	app.use(router);
	app.mount("#app");
}

bootstrap();