import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  base: './',
  build: {
    outDir: fileURLToPath(new URL('../../assets/go-vue/', import.meta.url)),
    // Keep earlier hashed assets available to already-open pilot tabs.
    emptyOutDir: false,
    manifest: true,
    rollupOptions: { input: [fileURLToPath(new URL('src/main.ts', import.meta.url)), fileURLToPath(new URL('src/teacher.ts', import.meta.url))] },
    sourcemap: false,
  },
})
