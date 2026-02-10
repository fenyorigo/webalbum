import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
  plugins: [vue()],
  base: "/dist/",
  build: {
    outDir: "../backend/public/dist",
    emptyOutDir: true
  },
  server: {
    proxy: {
      "/api": {
        target: "https://localhost:8445",
        changeOrigin: true,
        secure: false
      }
    }
  }
});
