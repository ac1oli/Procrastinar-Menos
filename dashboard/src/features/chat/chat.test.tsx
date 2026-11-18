import { screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { db, sent, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

type Sent = { message: string; conversation_id?: string; private?: boolean; history?: { role: string; content: string }[] }

async function say(user: ReturnType<typeof renderApp>['user'], text: string) {
  await user.type(await screen.findByLabelText('Mensagem para o NEXO'), text)
  await user.click(screen.getByRole('button', { name: 'Enviar' }))
}

describe('conversa com o NEXO', () => {
  it('com histórico ligado, continua a conversa guardada pelo id', async () => {
    signedIn({ 'nexo.history': true })
    const { user } = renderApp('/conversar')

    await say(user, 'Quero estudar mais')
    expect(await screen.findByText('Resposta fictícia do NEXO.')).toBeInTheDocument()
    await say(user, 'Pode ser de manhã?')
    await waitFor(() => expect(sent('POST', '/api/nexo/messages')).toHaveLength(2))

    const [first, second] = sent('POST', '/api/nexo/messages') as Sent[]
    expect(first.conversation_id).toBeUndefined()
    expect(second.conversation_id).toBe(db.conversations[0].id)
    expect(second.history).toBeUndefined()
  })

  it('conversa privada envia private e os turnos do navegador, e não é guardada', async () => {
    signedIn({ 'nexo.history': true })
    const { user } = renderApp('/conversar')

    await user.click(await screen.findByRole('switch', { name: /Conversa privada/ }))
    await say(user, 'Assunto delicado')
    await screen.findByText('Resposta fictícia do NEXO.')
    await say(user, 'Continuando')
    await waitFor(() => expect(sent('POST', '/api/nexo/messages')).toHaveLength(2))

    const [, second] = sent('POST', '/api/nexo/messages') as Sent[]
    expect(second.private).toBe(true)
    expect(second.history?.map((t) => t.content)).toEqual(['Assunto delicado', 'Resposta fictícia do NEXO.'])
    expect(db.conversations).toHaveLength(0)
  })

  it('sem histórico, avisa que nada é guardado e mantém o contexto no navegador', async () => {
    signedIn()
    const { user } = renderApp('/conversar')

    await say(user, 'Oi')
    expect(await screen.findByText(/O histórico está desligado/)).toBeInTheDocument()
  })

  it('mostra as memórias que o NEXO considerou', async () => {
    signedIn({ 'nexo.memory': true })
    db.memoriesUsed = [{ id: 'm-x', kind: 'preferencia', content: 'Prefere estudar de manhã.' }]
    const { user } = renderApp('/conversar')

    await say(user, 'Quando estudar?')
    expect(await screen.findByText('O NEXO considerou 1 memória(s)')).toBeInTheDocument()
    expect(screen.getByText('Prefere estudar de manhã.', { exact: false })).toBeInTheDocument()
  })

  it('com a IA fora do ar, explica o motivo e devolve a mensagem para a caixa', async () => {
    signedIn()
    db.aiDown = 'timeout'
    const { user } = renderApp('/conversar')

    await say(user, 'Mensagem importante')

    expect(await screen.findByText(/O modelo demorou demais para responder/)).toBeInTheDocument()
    expect(screen.getByLabelText('Mensagem para o NEXO')).toHaveValue('Mensagem importante')
    expect(screen.getByText(/O NEXO está indisponível agora/)).toBeInTheDocument()
  })

  it('abre uma conversa do histórico e continua nela', async () => {
    signedIn({ 'nexo.history': true })
    db.conversations.push({
      id: 'c-antiga',
      title: 'Plano de estudos',
      messages_count: 2,
      last_message_at: '2026-11-17T10:00:00Z',
      expires_at: null,
      messages: [
        { id: 'a', role: 'user', content: 'Mensagem antiga', masked: 0, created_at: '2026-11-17T10:00:00Z' },
        { id: 'b', role: 'assistant', content: 'Resposta antiga', masked: 0, created_at: '2026-11-17T10:00:01Z' },
      ],
    })
    const { user } = renderApp('/conversar?c=c-antiga')

    expect(await screen.findByText('Mensagem antiga')).toBeInTheDocument()
    await say(user, 'E agora?')
    await waitFor(() => expect((sent('POST', '/api/nexo/messages')[0] as Sent).conversation_id).toBe('c-antiga'))
    expect(await screen.findByText('Resposta antiga')).toBeInTheDocument()
  })
})
