import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const base = process.env.VITE_BASE?.replace(/\/?$/, '/') || '/adm/'

// https://vite.dev/config/
export default defineConfig({
  base,
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    target: 'es2022',
    sourcemap: true,
  },
  server: {
    proxy: {
      [`${base}api`]: {
        target: 'http://localhost:80',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})
