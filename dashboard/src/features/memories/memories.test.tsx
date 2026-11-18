import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { db, memory, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

describe('memórias', () => {
  it('cria memória e mostra origem, evidência e confiança', async () => {
    signedIn({ 'nexo.memory': true })
    const { user } = renderApp('/memorias')

    await user.click(await screen.findByRole('button', { name: /Nova memória/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Memória'), 'Está aprendendo Laravel.')
    await user.click(within(dialog).getByRole('button', { name: 'Guardar memória' }))

    const card = await screen.findByTestId('memory-card')
    expect(within(card).getByText('Está aprendendo Laravel.')).toBeInTheDocument()
    expect(within(card).getByText('Criada por você')).toBeInTheDocument()
    expect(within(card).getByText(/Declarada · Confiança alta/)).toBeInTheDocument()
  })

  it('mostra a recusa do backend para identificadores', async () => {
    signedIn({ 'nexo.memory': true })
    const { user } = renderApp('/memorias')

    await user.click(await screen.findByRole('button', { name: /Nova memória/ }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Memória'), 'Meu CPF é 123.456.789-09')
    await user.click(within(dialog).getByRole('button', { name: 'Guardar memória' }))

    expect(await within(dialog).findByText(/não podem conter documentos/)).toBeInTheDocument()
    expect(db.memories).toHaveLength(0)
  })

  it('com a memória desligada, avisa e não deixa criar, mas mantém consulta', async () => {
    signedIn()
    db.memories.push(memory({ content: 'Memória antiga' }))
    renderApp('/memorias')

    expect(await screen.findByText(/A memória de longo prazo está desligada/)).toBeInTheDocument()
    expect(await screen.findByText('Memória antiga')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Nova memória/ })).toBeDisabled()
  })

  it('revisa, arquiva e exclui com confirmação', async () => {
    signedIn({ 'nexo.memory': true })
    db.memories.push(memory({ id: 'm-r', content: 'Antiga', status: 'needs_review' }))
    const { user } = renderApp('/memorias')

    await user.click(await screen.findByRole('button', { name: /Continua válida/ }))
    await waitFor(() => expect(db.memories[0].status).toBe('active'))

    await user.click(await screen.findByRole('button', { name: /Arquivar/ }))
    await waitFor(() => expect(db.memories[0].status).toBe('archived'))

    await user.click(screen.getByRole('button', { name: /^Excluir$/ }))
    const dialog = await screen.findByRole('dialog')
    expect(db.memories).toHaveLength(1)
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }))
    await waitFor(() => expect(db.memories).toHaveLength(0))
  })
})

describe('sugestões de memória', () => {
  const suggestion = (id: string, content: string) => ({
    id,
    kind: 'preferencia' as const,
    content,
    rationale: 'Você mencionou.',
    source: { conversation_id: 'c-1', message_id: 'msg-1' },
    created_at: '2026-11-18T12:00:00Z',
    expires_at: '2026-12-02T12:00:00Z',
  })

  it('aceita e recusa sugestões', async () => {
    signedIn({ 'nexo.memory': true, 'nexo.history': true, 'nexo.memory.suggestions': true })
    db.suggestions.push(suggestion('s-1', 'Prefere estudar cedo.'), suggestion('s-2', 'Gosta de café.'))
    const { user } = renderApp('/memorias?aba=sugestoes')

    const cards = await screen.findAllByTestId('suggestion-card')
    expect(within(cards[0]).getByText('Por quê: Você mencionou.')).toBeInTheDocument()
    await user.click(within(cards[0]).getByRole('button', { name: /Guardar/ }))
    await waitFor(() => expect(db.memories.map((m) => m.origin)).toEqual(['suggestion_confirmed']))

    await user.click(within(await screen.findByTestId('suggestion-card')).getByRole('button', { name: /Recusar/ }))
    await waitFor(() => expect(db.suggestions).toHaveLength(0))
    expect(db.memories).toHaveLength(1)
  })

  it('edição sensível de sugestão é recusada e explicada', async () => {
    signedIn({ 'nexo.memory': true })
    db.suggestions.push(suggestion('s-1', 'Prefere estudar cedo.'))
    const { user } = renderApp('/memorias?aba=sugestoes')

    await user.click(await screen.findByRole('button', { name: /Editar e guardar/ }))
    const dialog = await screen.findByRole('dialog')
    const field = within(dialog).getByLabelText('Memória')
    await user.clear(field)
    await user.type(field, 'Estuda cedo por causa da ansiedade.')
    await user.click(within(dialog).getByRole('button', { name: 'Guardar memória' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Dados sensíveis não entram por sugestão.')
    expect(db.suggestions).toHaveLength(1)
  })
})
