import "./assets/main.css";

import { createApp } from "vue";
import { createPinia } from "pinia";

import App from "./App.vue";
import router from "./router";

if (localStorage.getItem("citelens-theme") === "dark") {
	document.documentElement.classList.add("dark");
}

const app = createApp(App);

app.use(createPinia());
app.use(router);

app.mount("#app");
