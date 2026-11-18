import { defineConfig, devices } from '@playwright/test'

import { baseURLFor, type Target } from './target'

/**
 * Testes ponta a ponta com navegador real (Chromium sem janela). Banco: procrastinar_menos_e2e.
 * - playwright.config.ts         → alvo "vite" (dev server, proxy para o backend isolado :8010);
 * - playwright.laravel.config.ts → alvo "laravel" (o backend isolado serve o dashboard publicado).
 * Antes: pwsh ../infrastructure/scripts/e2e-db.ps1 (e publish-dashboard.ps1 para o alvo laravel).
 */
export function createConfig(target: Target) {
  process.env.PM_E2E_TARGET = target
  const api = process.env.PM_E2E_API ?? 'http://127.0.0.1:8010'

  return defineConfig({
    testDir: './e2e',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 300_000, // respostas reais do modelo local
    expect: { timeout: 15_000 },
    reporter: [['list']],
    outputDir: './test-results',
    use: {
      baseURL: baseURLFor(target),
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
      ...(target === 'laravel' ? [{ name: 'producao', testMatch: 'producao.spec.ts', use: { ...devices['Desktop Chrome'] } }] : []),
    ],
    webServer:
      target === 'laravel'
        ? undefined
        : {
            command: 'pnpm dev',
            url: 'http://localhost:5173',
            // Nunca reaproveita um dev server já aberto: ele poderia estar ligado ao backend principal.
            reuseExistingServer: false,
            env: { PM_API_URL: api },
            timeout: 60_000,
          },
  })
}
