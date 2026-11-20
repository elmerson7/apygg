/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// El navegador habla SIEMPRE con `/api` (mismo origen); Vite lo reenvía a la API real quitando el prefijo
// (la API no usa /api). Así no hace falta CORS. `VITE_API_URL` apunta a la API (por defecto http://localhost:8000).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = process.env.VITE_API_URL || env.VITE_API_URL || 'http://localhost:8000'
  const proxy = {
    '/api': { target, changeOrigin: true, rewrite: (path: string) => path.replace(/^\/api/, '') },
  }

  return {
    plugins: [react()],
    server: { port: 5173, proxy, allowedHosts: true },
    preview: { port: 4173, proxy, allowedHosts: true },
    test: { environment: 'jsdom', include: ['src/**/*.test.{ts,tsx}'], globals: false },
  }
})
