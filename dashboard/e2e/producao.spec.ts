/**
 * Produção local (ADR-0010): o dashboard publicado, servido pelo próprio Laravel, no Chromium.
 * Só roda com `pnpm test:e2e:laravel`. Backends isolados: :8010 (com IA) e :8011 (IA fora do ar).
 * Contas fictícias (example.com), criadas e excluídas aqui, no banco procrastinar_menos_e2e.
 */
import { expect, test, type Browser, type Page } from '@playwright/test'

import { choose, fakeEmail, password, register } from './helpers'
import { currentBaseURL, noAiURL } from './target'

const scopes = ['Guardar o histórico', 'Memória de longo prazo', 'Sugestões de memória', 'Memórias sensíveis', 'Usar a sua rotina', 'Usar os seus objetivos']

async function newAccount(browser: Browser, baseURL: string, name: string, allow: string[] = []) {
  const context = await browser.newContext({ baseURL, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' })
  const page = await context.newPage()
  const email = fakeEmail('e2e-prod')
  await register(page, name, email)
  for (const scope of scopes) await choose(page, scope, allow.includes(scope) ? 'Permitir' : 'Não permitir')
  await page.getByRole('button', { name: 'Salvar e continuar' }).click()
  await expect(page.getByRole('heading', { name: new RegExp(`${name.split(' ')[0]}\\.`) })).toBeVisible()
  return { context, page, email }
}

/** Chamada à API de dentro da página, como o app faz (cookies, Origin e token CSRF do navegador). */
async function apiStatus(page: Page, path: string, method = 'GET', body?: unknown): Promise<number> {
  return page.evaluate(
    async ({ path, method, body }) => {
      const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
      const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' }
      if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)
      const response = await fetch(path, { method, credentials: 'same-origin', headers, body: body === undefined ? undefined : JSON.stringify(body) })
      return response.status
    },
    { path, method, body },
  )
}

async function deleteAccount(page: Page) {
  await page.goto('/privacidade')
  await page.getByRole('button', { name: 'Excluir minha conta' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Confirme sua senha').fill(password)
  await dialog.getByRole('button', { name: 'Excluir conta' }).click()
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
}

test.describe('servido pelo Laravel', () => {
  test('index sem cache, com CSP e sem cookie; arquivos com hash em cache imutável', async ({ page, request }) => {
    const response = await page.goto('/memorias')
    const headers = response!.headers()
    expect(response!.status()).toBe(200)
    expect(headers['cache-control']).toContain('no-cache')
    expect(headers['content-security-policy']).toContain("script-src 'self'")
    expect(headers['x-frame-options']).toBe('DENY')
    expect(headers['x-content-type-options']).toBe('nosniff')
    expect(headers['x-dashboard-release']).toBeTruthy()
    expect(headers['set-cookie']).toBeUndefined()

    const html = await response!.text()
    const script = html.match(/\/app\/assets\/index-[\w-]+\.js/)![0]
    const asset = await request.get(script)
    expect(asset.status()).toBe(200)
    expect(asset.headers()['cache-control']).toBe('public, max-age=31536000, immutable')
    expect(asset.headers()['content-type']).toContain('text/javascript')

    // Link direto para uma tela interna carrega o app (aqui, sem sessão, vai para a entrada).
    await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
  })

  test('o fallback da interface não captura a API nem arquivos ausentes', async ({ request }) => {
    const api = await request.get('/api/rota-inexistente', { headers: { Accept: 'application/json' } })
    expect(api.status()).toBe(404)
    expect(api.headers()['content-type']).toContain('application/json')

    const missing = await request.get('/app/assets/index-versao-antiga.js')
    expect(missing.status()).toBe(404)
    expect(missing.headers()['content-type']).not.toContain('text/html')

    const traversal = await request.get('/app/%2e%2e/%2e%2e/.env')
    expect(traversal.status()).toBe(404)
    expect(await traversal.text()).not.toContain('APP_KEY')

    expect((await request.get('/api/me', { headers: { Accept: 'application/json' } })).status()).toBe(401)
  })

  test('CSRF: sem token só passa o que o navegador atesta ser da mesma origem', async ({ browser }) => {
    const { context, page } = await newAccount(browser, currentBaseURL(), 'Pessoa Csrf')
    const origin = currentBaseURL()
    const xsrf = decodeURIComponent((await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value)
    // context.request leva os cookies da sessão, mas não o Sec-Fetch-Site do navegador.
    const post = (headers: Record<string, string>) =>
      context.request.put('/api/nexo/preferences', {
        headers: { Accept: 'application/json', Origin: origin, Referer: `${origin}/privacidade`, ...headers },
        data: { history_retention_days: 30 },
      })

    expect((await post({})).status()).toBe(419) // sem token e sem prova de origem
    expect((await post({ 'Sec-Fetch-Site': 'cross-site' })).status()).toBe(419) // outro site
    expect((await post({ 'Sec-Fetch-Site': 'same-site' })).status()).toBe(419) // outra porta/subdomínio
    expect((await post({ 'X-XSRF-TOKEN': xsrf })).status()).toBe(200) // com o token

    // No navegador, a própria página (Sec-Fetch-Site: same-origin, que o JS não consegue forjar) é
    // aceita pelo Laravel 13 mesmo sem token; o app envia o token de qualquer forma.
    expect(await apiStatus(page, '/api/nexo/preferences', 'PUT', { history_retention_days: 90 })).toBe(200)

    await deleteAccount(page)
    await context.close()
  })

  test('logout invalida a sessão: o cookie antigo não abre mais rotas protegidas', async ({ browser }) => {
    const { context, page, email } = await newAccount(browser, currentBaseURL(), 'Pessoa Sessao')
    const oldCookies = await context.cookies()
    expect(await apiStatus(page, '/api/me')).toBe(200)

    await page.getByRole('button', { name: 'Menu da conta' }).click()
    await page.getByRole('menuitem', { name: /Sair/ }).click()
    await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()

    const replay = await browser.newContext({ baseURL: currentBaseURL() })
    await replay.addCookies(oldCookies)
    const replayPage = await replay.newPage()
    await replayPage.goto('/entrar')
    expect(await apiStatus(replayPage, '/api/me')).toBe(401)
    await replay.close()

    // Volta a entrar para excluir a conta fictícia.
    await page.getByLabel('E-mail').fill(email)
    await page.getByLabel('Senha').fill(password)
    await page.getByRole('button', { name: 'Entrar' }).click()
    await expect(page.getByRole('heading', { name: /Pessoa\./ })).toBeVisible()
    await deleteAccount(page)
    await context.close()
  })

  test('isolamento entre contas: B não vê nem acessa a memória de A', async ({ browser }) => {
    const a = await newAccount(browser, currentBaseURL(), 'Pessoa Alfa', ['Memória de longo prazo'])
    const b = await newAccount(browser, currentBaseURL(), 'Pessoa Beta', ['Memória de longo prazo'])

    await a.page.goto('/memorias')
    await a.page.getByRole('button', { name: /Nova memória/ }).click()
    const dialog = a.page.getByRole('dialog')
    await dialog.getByRole('textbox', { name: 'Memória' }).fill('Memória fictícia só da conta Alfa.')
    const created = a.page.waitForResponse((r) => r.url().endsWith('/api/nexo/memories') && r.request().method() === 'POST')
    await dialog.getByRole('button', { name: 'Guardar memória' }).click()
    const memoryId = ((await (await created).json()) as { data: { id: string } }).data.id
    expect(await apiStatus(a.page, `/api/nexo/memories/${memoryId}`)).toBe(200)

    await b.page.goto('/memorias')
    await expect(b.page.getByText('Nenhuma memória ainda')).toBeVisible()
    await expect(b.page.locator('main')).not.toContainText('conta Alfa')
    expect(await apiStatus(b.page, `/api/nexo/memories/${memoryId}`)).toBe(404)
    expect(await apiStatus(b.page, `/api/nexo/memories/${memoryId}`, 'DELETE')).toBe(404)
    expect(await apiStatus(a.page, `/api/nexo/memories/${memoryId}`)).toBe(200) // continua intacta

    await deleteAccount(a.page)
    await deleteAccount(b.page)
    await a.context.close()
    await b.context.close()
  })

  test('IA fora do ar: a conversa explica e devolve a mensagem; rotina continua funcionando', async ({ browser }) => {
    const { context, page } = await newAccount(browser, noAiURL, 'Pessoa Offline')

    await expect(page.getByText('NEXO indisponível')).toBeVisible()
    await page.goto('/conversar')
    await page.getByLabel('Mensagem para o NEXO').fill('Mensagem que não pode se perder')
    await page.getByRole('button', { name: 'Enviar' }).click()
    await expect(page.getByRole('alert')).toBeVisible({ timeout: 60_000 })
    await expect(page.getByLabel('Mensagem para o NEXO')).toHaveValue('Mensagem que não pode se perder')

    await page.goto('/rotina')
    await page.getByRole('button', { name: /Novo bloco/ }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Título').fill('Bloco sem IA')
    await dialog.getByRole('checkbox', { name: 'Segunda' }).click()
    await dialog.getByRole('button', { name: 'Salvar' }).click()
    await expect(page.getByText('Bloco sem IA')).toBeVisible()

    await deleteAccount(page)
    await context.close()
  })

  test('depois de uma publicação nova, um trecho ausente recarrega o app uma vez e segue funcionando', async ({ browser }) => {
    const { context, page } = await newAccount(browser, currentBaseURL(), 'Pessoa Versao')
    const errors: string[] = []
    page.on('pageerror', (e) => errors.push(e.message))

    // Simula a versão antiga sumindo do servidor: o trecho da tela de memórias responde 404 uma vez.
    let failed = 0
    await page.route(/\/app\/assets\/MemoriesPage-[\w-]+\.js$/, async (route) => {
      if (failed++ === 0) return route.fulfill({ status: 404, contentType: 'text/plain', body: 'Arquivo não encontrado.' })
      return route.continue()
    })
    let loads = 0
    page.on('load', () => loads++)

    await page.getByRole('navigation', { name: 'Principal' }).getByRole('link', { name: /Memórias/ }).click()
    await expect(page.getByRole('heading', { name: 'Memórias', level: 1 })).toBeVisible()
    expect(failed).toBeGreaterThanOrEqual(2) // falhou uma vez e carregou depois
    expect(loads).toBe(1) // exatamente um recarregamento
    expect(errors).toEqual([])

    await deleteAccount(page)
    await context.close()
  })
})

test.describe('perfil e fuso', () => {
  test('nome e fuso mudam o painel; trocar a senha derruba a outra sessão e mantém esta', async ({ browser }) => {
    const { context, page, email } = await newAccount(browser, currentBaseURL(), 'Pessoa Perfil')

    // Segunda sessão da mesma conta, como outro navegador.
    const other = await browser.newContext({ baseURL: currentBaseURL() })
    const otherPage = await other.newPage()
    await otherPage.goto('/entrar')
    await otherPage.getByLabel('E-mail').fill(email)
    await otherPage.getByLabel('Senha').fill(password)
    await otherPage.getByRole('button', { name: 'Entrar' }).click()
    await expect(otherPage.getByRole('heading', { name: /Pessoa\./ })).toBeVisible()

    await page.goto('/perfil')
    await page.getByLabel('Nome').fill('Renomeada Perfil')
    await page.getByRole('button', { name: 'Salvar nome' }).click()
    await page.getByLabel('Filtrar fusos').fill('New_York')
    await page.getByLabel('Fuso da conta').selectOption('America/New_York')
    await page.getByRole('button', { name: 'Salvar fuso' }).click()
    await expect(page.getByRole('status', { name: 'Situação do fuso atual' })).toContainText(/America\/New_York · UTC−0[45]:00/)
    await expect(page.getByRole('status', { name: 'Situação do fuso atual' })).toContainText('Próxima mudança de horário')

    await page.getByLabel('Senha atual', { exact: true }).last().fill(password)
    await page.getByLabel('Nova senha', { exact: true }).fill('novaSenhaFicticia789')
    await page.getByLabel('Confirme a nova senha').fill('novaSenhaFicticia789')
    await page.getByRole('button', { name: 'Alterar senha' }).click()
    await expect(page.getByText(/1 outra\(s\) sessão\(ões\) foram encerradas/)).toBeVisible()

    expect(await apiStatus(otherPage, '/api/me')).toBe(401)
    expect(await apiStatus(page, '/api/me')).toBe(200)

    await page.goto('/')
    await expect(page.getByRole('heading', { name: /Renomeada\./ })).toBeVisible()

    // Exclui a conta com a senha nova.
    await page.goto('/privacidade')
    await page.getByRole('button', { name: 'Excluir minha conta' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Confirme sua senha').fill('novaSenhaFicticia789')
    await dialog.getByRole('button', { name: 'Excluir conta' }).click()
    await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
    await other.close()
    await context.close()
  })
})
