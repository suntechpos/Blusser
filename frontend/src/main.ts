import { createApp } from "vue";
import "./style.css";
import App from "./App.vue";
import { accessSurface } from "./services/accessSurface";

createApp(App).directive("access-surface", accessSurface).mount("#app");
