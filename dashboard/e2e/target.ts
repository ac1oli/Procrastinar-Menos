/**
 * Alvo dos testes no navegador:
 * - "vite": dev server em localhost:5173 com proxy para o backend isolado (:8010);
 * - "laravel": o próprio backend isolado servindo o dashboard publicado (produção local, ADR-0010).
 * Os dois usam o banco procrastinar_menos_e2e; nunca o principal.
 *
 * O alvo vai em PM_E2E_TARGET, definido pelo arquivo de configuração antes de criar os workers
 * (que herdam o ambiente). Por isso tudo aqui é lido na hora, não na importação.
 */
export type Target = 'vite' | 'laravel'

export const currentTarget = (): Target => (process.env.PM_E2E_TARGET === 'laravel' ? 'laravel' : 'vite')
export const baseURLFor = (target: Target) => (target === 'laravel' ? 'http://localhost:8010' : 'http://localhost:5173')
export const currentBaseURL = () => baseURLFor(currentTarget())
/** Backend isolado sem IA (porta fechada no lugar do ai-service), servindo a mesma versão publicada. */
export const noAiURL = 'http://localhost:8011'
