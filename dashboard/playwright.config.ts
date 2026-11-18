import { defineConfig, devices } from '@playwright/test'

/**
 * Testes ponta a ponta com navegador real (Chromium sem janela).
 *
 * O dev server do Vite sobe apontando o proxy para o backend ISOLADO de E2E
 * (pm-backend-e2e, banco procrastinar_menos_e2e). O banco principal nunca é usado.
 * Rodar: pwsh ../infrastructure/scripts/e2e-db.ps1 && pnpm test:e2e
 */
const api = process.env.PM_E2E_API ?? 'http://127.0.0.1:8010'

export default defineConfig({
  testDir: './e2e',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 300_000, // respostas reais do modelo local
  expect: { timeout: 15_000 },
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:5173',
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    // Sem rastros, vídeos ou capturas: nada de conteúdo de tela guardado em disco.
    trace: 'off',
    video: 'off',
    screenshot: 'off',
  },
  projects: [
    { name: 'desktop', testMatch: 'fluxo-completo.spec.ts', use: { ...devices['Desktop Chrome'] } },
    { name: 'celular', testMatch: 'responsivo.spec.ts', use: { ...devices['Pixel 7'] } },
  ],
  webServer: {
    command: 'pnpm dev',
    url: 'http://localhost:5173',
    // Nunca reaproveita um dev server já aberto: ele poderia estar ligado ao backend principal.
    reuseExistingServer: false,
    env: { PM_API_URL: api },
    timeout: 60_000,
  },
})
