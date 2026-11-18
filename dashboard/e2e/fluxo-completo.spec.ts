/**
 * Fluxo completo no Chromium real: dashboard (Vite) → proxy → backend isolado → IA real.
 * Uma conta fictícia nasce e é excluída aqui; tudo em procrastinar_menos_e2e.
 */
import { expect, test, type BrowserContext, type Page } from '@playwright/test'

import { choose, expectNoCredentialsInBrowser, fakeEmail, password, register, trackOrigins } from './helpers'
import { currentBaseURL } from './target'

const AI_TIMEOUT = 240_000
const email = fakeEmail('e2e')
const weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']
const today = () =>
  weekdays[['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(new Intl.DateTimeFormat('en-US', { timeZone: 'America/Sao_Paulo', weekday: 'short' }).format(new Date()))]

test.describe.configure({ mode: 'serial' })

let context: BrowserContext
let page: Page
let origins: Set<string>
const consoleErrors: string[] = []

test.beforeAll(async ({ browser }) => {
  context = await browser.newContext({ baseURL: currentBaseURL(), locale: 'pt-BR', timezoneId: 'America/Sao_Paulo', acceptDownloads: true })
  page = await context.newPage()
  origins = trackOrigins(page)
  page.on('console', (msg) => {
    if (msg.type() === 'error' && !/status of 40[19]|status of 422|status of 410/.test(msg.text())) consoleErrors.push(msg.text())
  })
})

test.afterAll(async () => {
  await context.close()
})

async function send(text: string) {
  await page.getByLabel('Mensagem para o NEXO').fill(text)
  await page.getByRole('button', { name: 'Enviar' }).click()
  await expect(page.getByText('O NEXO está pensando…')).toBeVisible()
  await expect(page.getByText('O NEXO está pensando…')).toBeHidden({ timeout: AI_TIMEOUT })
  await expect(page.getByRole('alert')).toHaveCount(0)
}

test('sem sessão, qualquer página leva à entrada', async () => {
  await page.goto('/memorias')
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
})

test('cadastro e onboarding (D1): nada pré-marcado, salva só com tudo decidido', async () => {
  await register(page, 'Pessoa Navegador', email)

  for (const radio of await page.getByRole('radio').all()) await expect(radio).not.toBeChecked()
  const save = page.getByRole('button', { name: 'Salvar e continuar' })
  await expect(save).toBeDisabled()

  await choose(page, 'Guardar o histórico', 'Permitir')
  await choose(page, 'Memória de longo prazo', 'Permitir')
  await choose(page, 'Sugestões de memória', 'Permitir')
  await choose(page, 'Memórias sensíveis', 'Não permitir')
  await choose(page, 'Usar a sua rotina', 'Permitir')
  await expect(save).toBeDisabled()
  await choose(page, 'Usar os seus objetivos', 'Permitir')
  await save.click()

  await expect(page.getByRole('heading', { name: /Pessoa\./ })).toBeVisible()
  await expectNoCredentialsInBrowser(page, context)
})

test('rotina e objetivos aparecem no painel', async () => {
  await page.goto('/rotina')
  await page.getByRole('button', { name: /Novo bloco/ }).click()
  let dialog = page.getByRole('dialog')
  await dialog.getByLabel('Título').fill('Estudo de Python')
  await dialog.getByRole('checkbox', { name: today() }).click()
  await dialog.getByRole('button', { name: 'Salvar' }).click()
  await expect(dialog).toBeHidden()
  await expect(page.getByText('Estudo de Python')).toBeVisible()

  await page.goto('/objetivos')
  await page.getByRole('button', { name: /Novo objetivo/ }).click()
  dialog = page.getByRole('dialog')
  await dialog.getByLabel('Título').fill('Aprender Python para análise de dados')
  await dialog.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByText('Aprender Python para análise de dados')).toBeVisible()

  await page.goto('/')
  await expect(page.getByText('Estudo de Python')).toBeVisible()
  await expect(page.getByText('Aprender Python para análise de dados')).toBeVisible()
})

test('memória: cria, edita e o backend recusa identificador', async () => {
  await page.goto('/memorias')
  await page.getByRole('button', { name: /Nova memória/ }).click()
  let dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox', { name: 'Memória' }).fill('Prefere estudar Python cedo.')
  await dialog.getByRole('button', { name: 'Guardar memória' }).click()
  await expect(dialog).toBeHidden()

  const card = page.getByTestId('memory-card').filter({ hasText: 'Prefere estudar Python cedo.' })
  await expect(card.getByText('Criada por você')).toBeVisible()
  await card.getByRole('button', { name: /Editar/ }).click()
  dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox', { name: 'Memória' }).fill('Prefere estudar Python no início da manhã, antes do trabalho.')
  await dialog.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByText('Prefere estudar Python no início da manhã, antes do trabalho.')).toBeVisible()

  await page.getByRole('button', { name: /Nova memória/ }).click()
  dialog = page.getByRole('dialog')
  await dialog.getByRole('textbox', { name: 'Memória' }).fill('Meu CPF é 123.456.789-09')
  await dialog.getByRole('button', { name: 'Guardar memória' }).click()
  await expect(dialog.getByText(/não podem conter documentos/)).toBeVisible()
  await dialog.press('Escape')
  await expect(page.getByTestId('memory-card')).toHaveCount(1)
})

test('conversa com a IA real: usa a memória e guarda mascarado', async () => {
  await page.goto('/conversar')
  await send('Qual o melhor horário para eu estudar Python hoje?')
  await expect(page.getByText(/O NEXO considerou \d+ memória/)).toBeVisible()
  await expect(page.locator('body')).not.toContainText('<think>')
  await expect(page).toHaveURL(/\?c=/)

  await send('Anota: meu CPF é 123.456.789-09.')
  const conversationId = new URL(page.url()).searchParams.get('c')!

  await page.goto(`/historico/${conversationId}`)
  await expect(page.getByText(/dado\(s\) mascarado\(s\)/).first()).toBeVisible()
  await expect(page.locator('main')).not.toContainText('123.456.789-09')
})

test('conversa privada não aparece no histórico', async () => {
  const saved = page.locator('main a[href^="/historico/"]')
  await page.goto('/historico')
  await expect(saved.first()).toBeVisible() // a conversa do teste anterior está guardada
  const before = await saved.count()

  await page.goto('/conversar')
  await page.getByRole('switch', { name: /Conversa privada/ }).click()
  await send('Isto é privado.')
  await expect(page.getByText('Isto é privado.')).toBeVisible()
  await expect(page).not.toHaveURL(/\?c=/)

  await page.goto('/historico')
  await expect(saved.first()).toBeVisible()
  expect(await saved.count()).toBe(before)
  await expect(page.locator('main')).not.toContainText('Isto é privado.')
})

test('histórico: busca e renomeia', async () => {
  await page.goto('/historico')
  await page.getByLabel('Buscar nas conversas').fill('Python')
  await page.getByRole('button', { name: /Buscar/ }).click()
  await page.locator('a[href^="/historico/"]').first().click()

  await page.getByRole('button', { name: /Renomear/ }).click()
  await page.getByLabel('Título da conversa').fill('Estudos de Python')
  await page.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByRole('heading', { name: 'Estudos de Python' })).toBeVisible()
})

test('sugestões: aceitar ou recusar o que o modelo propôs', async () => {
  await page.goto('/memorias?aba=sugestoes')
  await page.waitForLoadState('networkidle')
  // A quantidade depende do modelo real; o fluxo é testado quando houver sugestão.
  const cards = page.getByTestId('suggestion-card')
  const count = await cards.count()
  console.log(`Sugestões geradas pelo modelo real: ${count}`)
  if (count > 0) {
    await cards.first().getByRole('button', { name: /^Guardar$/ }).click()
    await expect(cards).toHaveCount(count - 1)
  }
  if (count > 1) {
    await cards.first().getByRole('button', { name: /Recusar/ }).click()
    await expect(cards).toHaveCount(count - 2)
  }
})

test('privacidade: retenção com prévia, revogação D7 e exportação de uso único', async () => {
  await page.goto('/privacidade')
  // A prévia do backend diz que nenhuma conversa passou do prazo: muda sem pedir confirmação.
  await page.getByLabel('Guardar conversas por').selectOption('30')
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await page.waitForLoadState('networkidle')
  await page.reload()
  await expect(page.getByLabel('Guardar conversas por')).toHaveValue('30')

  await page.getByRole('switch', { name: 'Memória de longo prazo' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByRole('radio', { name: /Excluir agora/ })).toBeChecked()
  const revoke = dialog.getByRole('button', { name: 'Revogar' })
  await expect(revoke).toBeDisabled()
  await dialog.getByRole('checkbox', { name: /exclusão é definitiva/ }).click()
  await revoke.click()
  await expect(dialog).toBeHidden()
  await expect(page.getByRole('switch', { name: 'Memória de longo prazo' })).not.toBeChecked()

  await page.getByLabel('Confirme sua senha').first().fill(password)
  await page.getByRole('button', { name: 'Pedir exportação' }).click()
  const downloadButton = page.getByRole('button', { name: /Baixar pacote/ })
  await expect(downloadButton).toBeVisible({ timeout: 60_000 })
  const [download] = await Promise.all([page.waitForEvent('download'), downloadButton.click()])
  expect(download.suggestedFilename()).toBe('procrastinar-menos-dados-nexo.zip')
  await download.delete() // o pacote fictício não fica em disco
  await expect(page.getByText(/O arquivo foi apagado do servidor/)).toBeVisible()

  await page.goto('/memorias')
  await expect(page.getByTestId('memory-card')).toHaveCount(0)
})

test('sair, entrar de novo e excluir a conta', async () => {
  await page.getByRole('button', { name: 'Menu da conta' }).click()
  await page.getByRole('menuitem', { name: /Sair/ }).click()
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()

  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill('senhaErrada123')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByText('E-mail ou senha incorretos.')).toBeVisible()
  await page.getByLabel('Senha').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('heading', { name: /Pessoa\./ })).toBeVisible()

  await page.goto('/privacidade')
  await page.getByRole('button', { name: 'Excluir minha conta' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Confirme sua senha').fill(password)
  await dialog.getByRole('button', { name: 'Excluir conta' }).click()
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()

  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
})

test('nenhuma requisição saiu da origem do dashboard e não houve erro de script', () => {
  expect([...origins]).toEqual([currentBaseURL()])
  expect(consoleErrors).toEqual([])
})
