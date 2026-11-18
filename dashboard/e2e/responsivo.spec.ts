/**
 * Layout de celular no Chromium real (viewport do Pixel 7): menu lateral, sem rolagem
 * horizontal. Conta fictícia criada e excluída no banco isolado de E2E.
 */
import { expect, test } from '@playwright/test'

import { choose, fakeEmail, password, register } from './helpers'

const pages = ['/', '/conversar', '/historico', '/memorias', '/rotina', '/objetivos', '/privacidade']

test('no celular, todas as telas cabem na largura e o menu navega', async ({ page }) => {
  await register(page, 'Pessoa Celular', fakeEmail('e2e-celular'))
  for (const scope of ['Guardar o histórico', 'Memória de longo prazo', 'Sugestões de memória', 'Memórias sensíveis', 'Usar a sua rotina', 'Usar os seus objetivos']) {
    await choose(page, scope, 'Não permitir')
  }
  await page.getByRole('button', { name: 'Salvar e continuar' }).click()
  await expect(page.getByRole('heading', { name: /Pessoa\./ })).toBeVisible()

  for (const path of pages) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow, `rolagem horizontal em ${path}`).toBeLessThanOrEqual(0)
  }

  await page.getByRole('button', { name: 'Abrir menu' }).click()
  await page.getByRole('dialog').getByRole('link', { name: /Memórias/ }).click()
  await expect(page).toHaveURL(/\/memorias$/)
  await expect(page.getByRole('dialog')).toBeHidden()

  await page.goto('/privacidade')
  await page.getByRole('button', { name: 'Excluir minha conta' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Confirme sua senha').fill(password)
  await dialog.getByRole('button', { name: 'Excluir conta' }).click()
  await expect(page.getByRole('heading', { name: 'Entrar' })).toBeVisible()
})
