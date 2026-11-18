/// <reference types="vitest/config" />
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// A API Laravel roda em 127.0.0.1:8000 (pm-backend). O proxy deixa o dashboard e a API
// na mesma origem: o cookie de sessão é HttpOnly e não há CORS nem tokens no navegador.
const apiTarget = process.env.PM_API_URL ?? 'http://127.0.0.1:8000'

export default defineConfig(({ command }) => ({
  // Em produção o Laravel serve o index.html e os arquivos ficam em /app/ (ADR-0010).
  // O dev server continua na raiz, com proxy para a API.
  base: command === 'build' ? '/app/' : '/',
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: apiTarget },
      '/sanctum': { target: apiTarget },
    },
  },
  preview: { host: 'localhost', port: 4173 },
  build: {
    // Sem source maps em produção: o código-fonte não fica exposto pelo servidor.
    sourcemap: false,
    rollupOptions: {
      output: {
        // Bibliotecas mudam menos que o código do app: ficam em arquivos próprios no cache.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router'],
          data: ['@tanstack/react-query'],
          ui: ['radix-ui', 'sonner'],
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    restoreMocks: true,
    include: ['src/**/*.test.{ts,tsx}'],
    // O contrato fala com o backend real isolado: roda só com `pnpm test:contract`.
    exclude: ['src/contract/**', 'node_modules/**'],
  },
}))
