import { screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'

import { db, memory, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

function conversation(id: string, title: string, text: string) {
  return {
    id,
    title,
    messages_count: 2,
    last_message_at: '2026-11-17T10:00:00Z',
    expires_at: '2027-02-15T10:00:00Z',
    messages: [
      { id: `${id}-1`, role: 'user' as const, content: text, masked: 1, created_at: '2026-11-17T10:00:00Z' },
      { id: `${id}-2`, role: 'assistant' as const, content: 'Resposta.', masked: 0, created_at: '2026-11-17T10:00:01Z' },
    ],
  }
}

describe('histórico de conversas', () => {
  beforeEach(() => {
    signedIn({ 'nexo.history': true })
    db.conversations.push(conversation('c-1', 'Python', 'Estudando Python com [CPF removido]'), conversation('c-2', 'Bolo', 'Receita de bolo'))
  })

  it('lista e busca por palavras', async () => {
    const { user } = renderApp('/historico')
    expect(await screen.findByText('Python')).toBeInTheDocument()
    expect(screen.getByText('Bolo')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Buscar nas conversas'), 'python')
    await user.click(screen.getByRole('button', { name: /Buscar/ }))

    await waitFor(() => expect(screen.queryByText('Bolo')).not.toBeInTheDocument())
    expect(screen.getByText('Python')).toBeInTheDocument()
  })

  it('mostra mensagens mascaradas e renomeia a conversa', async () => {
    const { user } = renderApp('/historico/c-1')
    expect(await screen.findByText('Estudando Python com [CPF removido]')).toBeInTheDocument()
    expect(screen.getByText(/1 dado\(s\) mascarado\(s\)/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Renomear/ }))
    const input = screen.getByLabelText('Título da conversa')
    await user.clear(input)
    await user.type(input, 'Estudos')
    await user.click(screen.getByRole('button', { name: 'Salvar' }))

    expect(await screen.findByRole('heading', { name: 'Estudos' })).toBeInTheDocument()
  })

  it('ao excluir, oferece apagar também as memórias nascidas da conversa', async () => {
    db.memories.push(memory({ content: 'Da conversa', source: { conversation_id: 'c-1', message_id: 'c-1-1' } }), memory({ content: 'Outra' }))
    const { user } = renderApp('/historico/c-1')

    await user.click(await screen.findByRole('button', { name: /Excluir$/ }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('checkbox', { name: /Excluir também 1 memória/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Excluir conversa' }))

    await waitFor(() => expect(db.conversations.map((c) => c.id)).toEqual(['c-2']))
    expect(db.memories.map((m) => m.content)).toEqual(['Outra'])
    expect(db.requests.some((r) => r.method === 'DELETE' && r.path === '/api/nexo/conversations/c-1?with_memories=1')).toBe(true)
  })

  it('apagar todo o histórico exige confirmação', async () => {
    const { user } = renderApp('/historico')
    await user.click(await screen.findByRole('button', { name: /Apagar todo o histórico/ }))

    const dialog = await screen.findByRole('dialog')
    expect(db.conversations).toHaveLength(2)
    await user.click(within(dialog).getByRole('button', { name: 'Apagar tudo' }))

    await waitFor(() => expect(db.conversations).toHaveLength(0))
    expect(await screen.findByText('Nenhuma conversa guardada')).toBeInTheDocument()
  })
})
