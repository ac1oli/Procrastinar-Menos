import { screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { db, memory, POLICY, sent, signedIn } from '@/test/server'
import { renderApp } from '@/test/render'

describe('privacidade: autorizações (D7)', () => {
  it('revogar escopo com dados pré-seleciona "excluir" e exige confirmação', async () => {
    signedIn({ 'nexo.memory': true })
    db.memories.push(memory())
    const { user } = renderApp('/privacidade')

    await user.click(await screen.findByRole('switch', { name: 'Memória de longo prazo' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('radio', { name: /Excluir agora/ })).toBeChecked()
    const revoke = within(dialog).getByRole('button', { name: 'Revogar' })
    expect(revoke).toBeDisabled()

    await user.click(within(dialog).getByRole('checkbox', { name: /exclusão é definitiva/ }))
    await user.click(revoke)

    await waitFor(() => expect(db.consents['nexo.memory']).toBe(false))
    expect(sent('PUT', '/api/nexo/consents/nexo.memory')).toEqual([{ granted: false, data_action: 'delete' }])
    expect(db.memories).toHaveLength(0)
  })

  it('"manter inativas" revoga sem apagar e sem exigir a confirmação de exclusão', async () => {
    signedIn({ 'nexo.history': true })
    const { user } = renderApp('/privacidade')

    await user.click(await screen.findByRole('switch', { name: 'Guardar o histórico de conversas' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('radio', { name: /Manter inativas/ }))
    await user.click(within(dialog).getByRole('button', { name: 'Revogar' }))

    await waitFor(() => expect(db.consents['nexo.history']).toBe(false))
    expect(sent('PUT', '/api/nexo/consents/nexo.history')).toEqual([{ granted: false, data_action: 'keep' }])
  })

  it('conceder envia a versão da política e respeita dependências', async () => {
    signedIn()
    const { user } = renderApp('/privacidade')

    expect(await screen.findByRole('switch', { name: 'Sugestões de memória' })).toBeDisabled()
    await user.click(screen.getByRole('switch', { name: 'Usar a sua rotina nas respostas' }))
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Permitir' }))

    await waitFor(() => expect(db.consents['nexo.context.routine']).toBe(true))
    expect(sent('PUT', '/api/nexo/consents/nexo.context.routine')).toEqual([{ granted: true, policy_version: POLICY }])
  })
})

describe('privacidade: retenção, exportação e conta', () => {
  it('avisa quantas conversas serão apagadas antes de encurtar a retenção', async () => {
    signedIn({ 'nexo.history': true })
    db.conversations.push({ id: 'c-1', title: 'Antiga', messages_count: 0, last_message_at: '2026-01-01T00:00:00Z', expires_at: null, messages: [] })
    const { user } = renderApp('/privacidade')

    await user.selectOptions(await screen.findByLabelText('Guardar conversas por'), '30')
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/1 conversa\(s\) já passaram desse prazo/)).toBeInTheDocument()
    expect(db.retention).toBe(90)

    await user.click(within(dialog).getByRole('button', { name: 'Mudar prazo' }))
    await waitFor(() => expect(db.retention).toBe(30))
  })

  it('exporta com senha e baixa o pacote uma vez', async () => {
    signedIn()
    URL.createObjectURL = vi.fn(() => 'blob:fake')
    URL.revokeObjectURL = vi.fn()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const { user } = renderApp('/privacidade')

    await user.type(await screen.findByLabelText('Confirme sua senha'), 'errada')
    await user.click(screen.getByRole('button', { name: 'Pedir exportação' }))
    expect(await screen.findByText('A senha está incorreta.')).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Confirme sua senha'))
    await user.type(screen.getByLabelText('Confirme sua senha'), db.password)
    await user.click(screen.getByRole('button', { name: 'Pedir exportação' }))
    await user.click(await screen.findByRole('button', { name: /Baixar pacote/ }, { timeout: 4000 }))

    expect(await screen.findByText(/O arquivo foi apagado do servidor/)).toBeInTheDocument()
    expect(click).toHaveBeenCalledOnce()
  })

  it('excluir a conta pede a senha e volta para a entrada', async () => {
    signedIn()
    const { user } = renderApp('/privacidade')

    await user.click(await screen.findByRole('button', { name: 'Excluir minha conta' }))
    const dialog = await screen.findByRole('dialog')
    const confirm = within(dialog).getByRole('button', { name: 'Excluir conta' })
    expect(confirm).toBeDisabled()
    await user.type(within(dialog).getByLabelText('Confirme sua senha'), db.password)
    await user.click(confirm)

    expect(await screen.findByRole('heading', { name: 'Entrar' })).toBeInTheDocument()
    expect(db.user).toBeNull()
  })
})
