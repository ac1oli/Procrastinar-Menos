import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { todayIso } from '@/lib/labels'
import { db, fakeUser, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

describe('rotina', () => {
  it('cria bloco escolhendo dias e mostra a validação do backend', async () => {
    signedIn()
    const { user } = renderApp('/rotina')

    await user.click(await screen.findByRole('button', { name: /Novo bloco/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Título'), 'Estudo de SQL')
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }))
    expect(await within(dialog).findByText('Escolha pelo menos um dia.')).toBeInTheDocument()

    await user.click(within(dialog).getByRole('checkbox', { name: 'Segunda' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Quarta' }))
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }))

    expect(await screen.findByText('Estudo de SQL')).toBeInTheDocument()
    expect(db.routine[0]).toMatchObject({ days_of_week: [1, 3], category: 'study', start_time: '19:00' })
  })
})

describe('objetivos', () => {
  it('cria, edita o estado e agrupa por estado', async () => {
    signedIn()
    const { user } = renderApp('/objetivos')

    await user.click(await screen.findByRole('button', { name: /Novo objetivo/ }))
    let dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Título'), 'Aprender Laravel')
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }))
    expect(await screen.findByText('Aprender Laravel')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Editar/ }))
    dialog = await screen.findByRole('dialog')
    await user.selectOptions(within(dialog).getByLabelText('Estado'), 'completed')
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(db.goals[0].status).toBe('completed'))
    expect(await screen.findByRole('heading', { name: 'Concluído' })).toBeInTheDocument()
  })
})

describe('painel', () => {
  it('mostra só os blocos de hoje, objetivos ativos e pendências reais', async () => {
    signedIn({ 'nexo.memory': true })
    const today = todayIso(fakeUser.timezone)
    const other = (today % 7) + 1
    db.routine.push(
      { id: 'r-1', title: 'Bloco de hoje', category: 'study', days_of_week: [today], start_time: '07:00', end_time: '08:00', crosses_midnight: false, notes: null, is_active: true, updated_at: '' },
      { id: 'r-2', title: 'Bloco de outro dia', category: 'work', days_of_week: [other], start_time: '09:00', end_time: '10:00', crosses_midnight: false, notes: null, is_active: true, updated_at: '' },
    )
    db.goals.push(
      { id: 'g-1', title: 'Objetivo ativo', description: null, type: 'learning', status: 'active', target_date: null, created_at: '', updated_at: '' },
      { id: 'g-2', title: 'Objetivo pausado', description: null, type: 'personal', status: 'paused', target_date: null, created_at: '', updated_at: '' },
    )
    renderApp('/')

    expect(await screen.findByText('Bloco de hoje')).toBeInTheDocument()
    expect(screen.queryByText('Bloco de outro dia')).not.toBeInTheDocument()
    expect(await screen.findByText('Objetivo ativo')).toBeInTheDocument()
    expect(screen.queryByText('Objetivo pausado')).not.toBeInTheDocument()
    expect(screen.getByText('Memória de longo prazo')).toBeInTheDocument()
  })

  it('o rascunho do painel vai para a conversa sem ser enviado sozinho', async () => {
    signedIn()
    const { user } = renderApp('/')

    await user.type(await screen.findByLabelText('Começar uma conversa'), 'Como organizo a semana?')
    await user.click(screen.getByRole('button', { name: /Conversar/ }))

    expect(await screen.findByLabelText('Mensagem para o NEXO')).toHaveValue('Como organizo a semana?')
    expect(db.requests.some((r) => r.path === '/api/nexo/messages')).toBe(false)
  })
})
