import { screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { db, fakeUser, POLICY, sent, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

describe('entrada e cadastro', () => {
  it('sem sessão, qualquer página leva à tela de entrada', async () => {
    renderApp('/memorias')
    expect(await screen.findByRole('heading', { name: 'Entrar' })).toBeInTheDocument()
  })

  it('mostra o erro de credenciais vindo da API', async () => {
    const { user } = renderApp('/entrar')
    await user.type(await screen.findByLabelText('E-mail'), fakeUser.email)
    await user.type(screen.getByLabelText('Senha'), 'senhaErrada123')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByText('E-mail ou senha incorretos.')).toBeInTheDocument()
  })

  it('entrar com autorizações pendentes leva ao onboarding', async () => {
    const { user } = renderApp('/entrar')
    await user.type(await screen.findByLabelText('E-mail'), fakeUser.email)
    await user.type(screen.getByLabelText('Senha'), db.password)
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByText(/decida o que o NEXO pode fazer/)).toBeInTheDocument()
  })

  it('cadastro envia o fuso detectado e mostra erros de validação', async () => {
    const { user } = renderApp('/criar-conta')
    await user.type(await screen.findByLabelText('Nome'), 'Pessoa Nova')
    await user.type(screen.getByLabelText('E-mail'), 'nova@example.com')
    await user.type(screen.getByLabelText('Senha'), 'curta1')
    await user.type(screen.getByLabelText('Confirme a senha'), 'curta1')
    await user.click(screen.getByRole('button', { name: 'Criar conta' }))

    expect(await screen.findByText('A senha deve ter pelo menos 10 caracteres.')).toBeInTheDocument()
    expect((sent('POST', '/api/auth/register')[0] as { timezone: string }).timezone).toBeTruthy()
  })
})

describe('onboarding (D1: nenhuma opção pré-marcada)', () => {
  const choose = async (user: ReturnType<typeof renderApp>['user'], title: string, option: 'Permitir' | 'Não permitir') => {
    const card = screen.getByRole('radiogroup', { name: new RegExp(title, 'i') })
    await user.click(within(card).getByRole('radio', { name: option }))
  }

  it('começa sem nenhuma resposta e só salva quando tudo for decidido', async () => {
    db.user = fakeUser
    const { user } = renderApp('/')

    await screen.findByText(/decida o que o NEXO pode fazer/)
    for (const radio of screen.getAllByRole('radio')) expect(radio).not.toBeChecked()
    const save = screen.getByRole('button', { name: 'Salvar e continuar' })
    expect(save).toBeDisabled()

    await choose(user, 'histórico', 'Permitir')
    expect(save).toBeDisabled()
    expect(screen.getByText('1 de 6 decididas')).toBeInTheDocument()
  })

  it('respeita dependências e não remarca nada sozinho', async () => {
    db.user = fakeUser
    const { user } = renderApp('/boas-vindas')
    await screen.findByText(/decida o que o NEXO pode fazer/)

    const suggestions = () => within(screen.getByRole('radiogroup', { name: /sugestões de memória/i }))
    expect(suggestions().getByRole('radio', { name: 'Permitir' })).toBeDisabled()

    await choose(user, 'Guardar o histórico', 'Permitir')
    await choose(user, 'Memória de longo prazo', 'Permitir')
    await user.click(suggestions().getByRole('radio', { name: 'Permitir' }))
    expect(suggestions().getByRole('radio', { name: 'Permitir' })).toBeChecked()

    // Recusar um requisito desfaz a escolha dependente sem marcar "não" por ela.
    await choose(user, 'Memória de longo prazo', 'Não permitir')
    expect(suggestions().getByRole('radio', { name: 'Permitir' })).not.toBeChecked()
    expect(suggestions().getByRole('radio', { name: 'Não permitir' })).not.toBeChecked()
  })

  it('salva concessões com a versão da política e recusas sem escolha de dados', async () => {
    db.user = fakeUser
    const { user } = renderApp('/boas-vindas')
    await screen.findByText(/decida o que o NEXO pode fazer/)

    await choose(user, 'Guardar o histórico', 'Permitir')
    await choose(user, 'Memória de longo prazo', 'Permitir')
    await choose(user, 'Sugestões de memória', 'Não permitir')
    await choose(user, 'Memórias sensíveis', 'Não permitir')
    await choose(user, 'Usar a sua rotina', 'Permitir')
    await choose(user, 'Usar os seus objetivos', 'Não permitir')
    await user.click(screen.getByRole('button', { name: 'Salvar e continuar' }))

    expect(await screen.findByRole('heading', { name: /Pessoa\./ })).toBeInTheDocument()
    expect(db.consents['nexo.history']).toBe(true)
    expect(db.consents['nexo.memory.suggestions']).toBe(false)
    const grants = sent('PUT', '/api/nexo/consents').filter((b) => (b as { granted: boolean }).granted)
    expect(grants).toHaveLength(3)
    for (const g of grants) expect(g).toMatchObject({ policy_version: POLICY })
  })

  it('quem já decidiu tudo vai direto ao painel', async () => {
    signedIn()
    renderApp('/boas-vindas')
    expect(await screen.findByRole('heading', { name: /Pessoa\./ })).toBeInTheDocument()
  })
})
