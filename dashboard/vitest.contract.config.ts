import path from 'node:path'
import { defineConfig } from 'vitest/config'

/**
 * Teste de contrato contra o backend real (isolado, banco procrastinar_menos_e2e).
 * Pré-requisito: pwsh infrastructure/scripts/e2e-db.ps1
 */
export default defineConfig({
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  test: {
    environment: 'node',
    include: ['src/contract/**/*.contract.test.ts'],
    testTimeout: 300_000,
    hookTimeout: 60_000,
    fileParallelism: false,
  },
})
