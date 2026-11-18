import { expect, type BrowserContext, type Page } from '@playwright/test'

export const password = 'senhaFicticia12345'

/** E-mail fictício e único (domínio reservado example.com). */
export const fakeEmail = (prefix: string) => `${prefix}+${Date.now()}@example.com`

/** Toda requisição precisa ficar na origem do dashboard: sem CDN, sem chamada direta à API. */
export function trackOrigins(page: Page): Set<string> {
  const origins = new Set<string>()
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.protocol.startsWith('http')) origins.add(url.origin)
  })
  return origins
}

export async function register(page: Page, name: string, email: string) {
  await page.goto('/criar-conta')
  await page.getByLabel('Nome').fill(name)
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha', { exact: true }).fill(password)
  await page.getByLabel('Confirme a senha').fill(password)
  await page.getByRole('button', { name: 'Criar conta' }).click()
  await expect(page.getByText(/decida o que o NEXO pode fazer/)).toBeVisible()
}

export async function choose(page: Page, scope: string, option: 'Permitir' | 'Não permitir') {
  await page.getByRole('radiogroup', { name: new RegExp(scope, 'i') }).getByRole('radio', { name: option, exact: true }).click()
}

/** A sessão é cookie HttpOnly; o navegador não guarda nada da conta, só o tema. */
export async function expectNoCredentialsInBrowser(page: Page, context: BrowserContext) {
  const session = (await context.cookies()).find((c) => /session/i.test(c.name))
  expect(session, 'cookie de sessão').toBeDefined()
  expect(session!.httpOnly).toBe(true)
  expect(await page.evaluate(() => document.cookie)).not.toContain(session!.name)

  const storage = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
  }))
  expect(storage.local.filter((k) => !/theme/i.test(k))).toEqual([])
  expect(storage.session).toEqual([])
}
