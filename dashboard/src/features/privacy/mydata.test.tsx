import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { db, fakeUser, memory, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

function conversation(id: string, messages = 2) {
  return {
    id,
    title: `Conversa ${id}`,
    messages_count: messages,
    last_message_at: '2026-11-17T10:00:00Z',
    expires_at: null,
    messages: Array.from({ length: messages }, (_, i) => ({
      id: `${id}-${i}`,
      role: (i % 2 ? 'assistant' : 'user') as 'user' | 'assistant',
      content: 'Texto fictício',
      masked: 0,
      created_at: '2026-11-17T10:00:00Z',
    })),
  }
}

describe('central Meus dados', () => {
  it('o endereço antigo leva à central, que aparece no menu', async () => {
    signedIn()
    renderApp('/privacidade')

    expect(await screen.findByRole('heading', { name: 'Meus dados', level: 1 })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Principal' })).toHaveTextContent('Meus dados')
  })

  it('mostra conta e inventário com as contagens do backend e atalhos para gerenciar', async () => {
    signedIn({ 'nexo.history': true, 'nexo.memory': true })
    db.conversations.push(conversation('c-1', 4))
    db.memories.push(memory({ content: 'A', pinned: true }), memory({ content: 'B', status: 'archived' }))
    renderApp('/meus-dados')

    expect(await screen.findByText(fakeUser.email)).toBeInTheDocument()
    expect(screen.getByText('America/Sao_Paulo (UTC−03:00)')).toBeInTheDocument()
    const inventory = await screen.findByRole('list', { name: 'Inventário dos dados' })
    expect(inventory).toHaveTextContent('1 conversa(s), 4 mensagem(ns)')
    expect(inventory).toHaveTextContent('2 memória(s)')
    expect(inventory).toHaveTextContent('1 arquivada(s) · 1 fixada(s)')
    expect(within(inventory).getByRole('link', { name: 'Gerenciar memórias' })).toHaveAttribute('href', '/memorias')
    expect(screen.getByRole('link', { name: /Editar perfil/ })).toHaveAttribute('href', '/perfil')
  })

  it('apagar o histórico mostra o que some e o que fica antes de confirmar', async () => {
    signedIn({ 'nexo.history': true, 'nexo.memory': true })
    db.conversations.push(conversation('c-1'), conversation('c-2'))
    db.memories.push(memory({ content: 'Da conversa', source: { conversation_id: 'c-1', message_id: 'c-1-0' } }))
    const { user } = renderApp('/meus-dados')

    await user.click(await screen.findByRole('button', { name: /Apagar todo o histórico/ }))
    const impact = await within(await screen.findByRole('dialog')).findByTestId('impact')
    expect(impact).toHaveTextContent('2 conversa(s) guardada(s), com 4 mensagem(ns)')
    expect(impact).toHaveTextContent('Continua guardado:1 memória(s) (1 perdem só o vínculo')
    expect(db.conversations).toHaveLength(2)

    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Apagar tudo' }))
    await waitFor(() => expect(db.conversations).toHaveLength(0))
    expect(db.memories).toHaveLength(1)
    expect(await screen.findByRole('list', { name: 'Inventário dos dados' })).toHaveTextContent('0 conversa(s), 0 mensagem(ns)')
  })

  it('excluir todas as memórias mostra fixadas e sensíveis antes de confirmar', async () => {
    signedIn({ 'nexo.memory': true })
    db.memories.push(memory({ content: 'A', pinned: true }), memory({ content: 'B', sensitivity: 'sensitive' }))
    const { user } = renderApp('/meus-dados')

    await user.click(await screen.findByRole('button', { name: /Excluir todas as memórias/ }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByTestId('impact')).toHaveTextContent('inclui 1 fixada(s) e 1 sensível(is)')
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }))
    expect(db.memories).toHaveLength(2)
  })

  it('excluir a conta lista tudo o que será apagado e o que fica anonimizado', async () => {
    signedIn()
    db.goals.push({ id: 'g-1', title: 'Objetivo', description: null, type: 'learning', status: 'active', target_date: null, created_at: '', updated_at: '' })
    const { user } = renderApp('/meus-dados')

    await user.click(await screen.findByRole('button', { name: 'Excluir minha conta' }))
    const dialog = await screen.findByRole('dialog')
    const impact = await within(dialog).findByTestId('impact')
    expect(impact).toHaveTextContent('perfil (nome, e-mail, fuso) e senha')
    expect(impact).toHaveTextContent('0 bloco(s) de rotina e 1 objetivo(s)')
    expect(impact).toHaveTextContent('2 registro(s) de segurança anonimizado(s)')
    expect(within(dialog).getByRole('button', { name: 'Excluir conta' })).toBeDisabled() // falta a senha
  })
})
