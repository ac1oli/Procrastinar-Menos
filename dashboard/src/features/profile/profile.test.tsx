import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { formatDate } from '@/lib/labels'
import { db, sent, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

describe('perfil', () => {
  it('abre pelo menu da conta e salva o nome normalizado pelo backend', async () => {
    signedIn()
    const { user } = renderApp('/')

    await user.click(await screen.findByRole('button', { name: 'Menu da conta' }))
    await user.click(await screen.findByRole('menuitem', { name: /Perfil e fuso horário/ }))

    const field = await screen.findByLabelText('Nome')
    await user.clear(field)
    await user.type(field, '  Nome   Novo ')
    await user.click(screen.getByRole('button', { name: 'Salvar nome' }))

    await waitFor(() => expect(db.user?.name).toBe('Nome Novo'))
    // O app só tira as bordas; espaços repetidos são normalizados pelo backend.
    expect(sent('PATCH', '/api/me')).toEqual([{ name: 'Nome   Novo' }])
  })

  it('não deixa enviar nome vazio', async () => {
    signedIn()
    const { user } = renderApp('/perfil')

    const field = await screen.findByLabelText('Nome')
    await user.clear(field)
    expect(screen.getByRole('button', { name: 'Salvar nome' })).toBeDisabled()
  })

  it('fuso: mostra a situação calculada no backend e salva o escolhido', async () => {
    signedIn()
    const { user } = renderApp('/perfil')

    const status = await screen.findByRole('status', { name: 'Situação do fuso atual' })
    expect(status).toHaveTextContent('America/Sao_Paulo · UTC−03:00')
    expect(status).toHaveTextContent('não tem mudança de horário prevista')

    await user.selectOptions(await screen.findByLabelText('Fuso da conta'), 'America/New_York')
    await user.click(screen.getByRole('button', { name: 'Salvar fuso' }))

    await waitFor(() => expect(db.user?.timezone).toBe('America/New_York'))
    expect(await screen.findByRole('status', { name: 'Situação do fuso atual' })).toHaveTextContent(/UTC−05:00.*início do horário de verão/s)
  })

  it('filtra a lista de fusos sem perder o atual', async () => {
    signedIn()
    const { user } = renderApp('/perfil')

    await user.type(await screen.findByLabelText('Filtrar fusos'), 'lisbon')
    const select = screen.getByLabelText('Fuso da conta')
    const values = within(select).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)
    expect(values).toEqual(['America/Sao_Paulo', 'Europe/Lisbon'])
  })

  it('e-mail exige a senha atual e mostra o erro do backend', async () => {
    signedIn()
    const { user } = renderApp('/perfil')

    await user.type(await screen.findByLabelText('Novo e-mail'), 'nova@example.com')
    await user.type(screen.getByLabelText('Senha atual', { selector: '#profile-email-password' }), 'errada123')
    await user.click(screen.getByRole('button', { name: 'Alterar e-mail' }))
    expect(await screen.findByText('A senha atual está incorreta.')).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Senha atual', { selector: '#profile-email-password' }))
    await user.type(screen.getByLabelText('Senha atual', { selector: '#profile-email-password' }), db.password)
    await user.click(screen.getByRole('button', { name: 'Alterar e-mail' }))
    await waitFor(() => expect(db.user?.email).toBe('nova@example.com'))
  })

  it('senha: confirmação divergente bloqueia o envio; sucesso informa as sessões encerradas', async () => {
    signedIn()
    const { user } = renderApp('/perfil')

    await user.type(await screen.findByLabelText('Senha atual', { selector: '#pw-current' }), db.password)
    await user.type(screen.getByLabelText('Nova senha'), 'novaSenha12345')
    await user.type(screen.getByLabelText('Confirme a nova senha'), 'outraCoisa123')
    expect(screen.getByText('A confirmação não confere.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Alterar senha' })).toBeDisabled()

    await user.clear(screen.getByLabelText('Confirme a nova senha'))
    await user.type(screen.getByLabelText('Confirme a nova senha'), 'novaSenha12345')
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }))

    expect(await screen.findByText(/2 outra\(s\) sessão\(ões\) foram encerradas/)).toBeInTheDocument()
    expect(db.password).toBe('novaSenha12345')
  })
})

describe('datas', () => {
  it('prazo sem hora não volta um dia por causa do fuso', () => {
    expect(formatDate('2026-12-01')).toBe(new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'UTC' }).format(Date.UTC(2026, 11, 1)))
    expect(formatDate('2026-12-01')).toContain('1 de dez')
  })

  it('data com hora é mostrada no fuso da conta', () => {
    expect(formatDate('2026-11-19T02:30:00Z', true, 'America/Sao_Paulo')).toBe('18/11/2026, 23:30')
    expect(formatDate('2026-11-19T02:30:00Z', true, 'Asia/Tokyo')).toBe('19/11/2026, 11:30')
  })
})
