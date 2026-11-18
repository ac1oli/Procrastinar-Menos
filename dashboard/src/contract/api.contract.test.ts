/// <reference types="node" />
/**
 * Contrato do dashboard com o backend REAL (isolado) e a IA real.
 *
 * Usa exatamente as funções de src/api/endpoints.ts que as telas usam, com
 * CSRF e cookie de sessão. Dados fictícios, em procrastinar_menos_e2e.
 * Rodar: pwsh infrastructure/scripts/e2e-db.ps1 && pnpm test:contract
 */
import { beforeAll, describe, expect, it } from 'vitest'

import { ApiError } from '@/api/client'
import { auth, consents, conversations, exports, goals, memories, myData, nexo, preferences, profile, routine, suggestions } from '@/api/endpoints'
import type { ConsentScopeId } from '@/api/types'
import { todayIso } from '@/lib/labels'

import { clearCookies, installBrowserLikeFetch } from './browser-like'

const BASE = process.env.PM_E2E_API ?? 'http://127.0.0.1:8010'
const email = `contrato+${Date.now()}@example.com`
const password = 'senhaFicticia12345'
const timezone = 'America/Sao_Paulo'

async function expectApiError(promise: Promise<unknown>, status: number): Promise<ApiError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(ApiError)
  expect((error as ApiError).status).toBe(status)
  return error as ApiError
}

describe('contrato dashboard ↔ backend real', () => {
  let conversationId: string
  let memoryId: string

  beforeAll(() => installBrowserLikeFetch(BASE))

  it('cadastro com CSRF abre a sessão; sem sessão, /me é 401', async () => {
    await expectApiError(auth.me(), 401)
    const user = await auth.register({ name: 'Pessoa Contrato', email, password, password_confirmation: password, timezone })
    expect(user.email).toBe(email)
    expect((await auth.me()).id).toBe(user.id)
  })

  it('onboarding: tudo começa sem decisão; concede com a versão e recusa', async () => {
    const state = await consents.get()
    expect(state.pending).toHaveLength(6)
    expect(state.scopes.every((s) => s.granted === null)).toBe(true)

    const grant: ConsentScopeId[] = ['nexo.history', 'nexo.memory', 'nexo.memory.suggestions', 'nexo.context.routine', 'nexo.context.goals']
    for (const scope of grant) await consents.grant(scope, state.policy_version)
    const final = await consents.revoke('nexo.memory.sensitive')
    expect(final.pending).toEqual([])
    await expectApiError(consents.grant('nexo.memory.sensitive', 'politica-antiga'), 422)
  })

  it('perfil: nome, fuso com horário de verão calculado no backend, e-mail e senha', async () => {
    expect(await profile.timezones()).toContain('America/New_York')
    const updated = await profile.update({ name: '  Pessoa   Contrato  ', timezone: 'America/New_York' })
    expect(updated.name).toBe('Pessoa Contrato')
    expect(updated.timezone_details.offset).toMatch(/^-0[45]:00$/)
    expect(updated.timezone_details.next_transition?.offset).toMatch(/^-0[45]:00$/)

    const invalid = await expectApiError(profile.update({ timezone: 'America/Atlantida' }), 422)
    expect(invalid.field('timezone')).toMatch(/IANA/)
    await expectApiError(profile.updateEmail('outro@example.com', 'senhaErrada123'), 422)
    await expectApiError(profile.updatePassword({ current_password: password, password: password, password_confirmation: password }), 422)

    // Volta ao fuso usado pelos demais testes.
    expect((await profile.update({ timezone })).timezone_details.next_transition).toBeNull()
  })

  it('rotina e objetivos', async () => {
    const block = await routine.create({
      title: 'Estudo de Python',
      category: 'study',
      days_of_week: [todayIso(timezone)],
      start_time: '07:00',
      end_time: '08:00',
      is_active: true,
    })
    expect((await routine.list()).map((b) => b.id)).toContain(block.id)
    const goal = await goals.create({ title: 'Aprender Python para análise de dados', type: 'learning' })
    expect((await goals.update(goal.id, { status: 'paused' })).status).toBe('paused')
    await goals.update(goal.id, { status: 'active' })
  })

  it('memória: criação com embedding real, recusa de identificador', async () => {
    const memory = await memories.create({ kind: 'preferencia', content: 'Prefere estudar Python no início da manhã, antes do trabalho.' })
    memoryId = memory.id
    expect(memory.origin).toBe('user_created')
    expect(memory.semantic_index).toBe('ready') // fila síncrona + IA real
    const error = await expectApiError(memories.create({ kind: 'contexto_de_vida', content: 'Meu CPF é 123.456.789-09' }), 422)
    expect(error.field('content')).toMatch(/documentos/)
  })

  it('conversa com IA real usa a memória e o contexto autorizado, e guarda mascarado', async () => {
    const reply = await nexo.send({ message: 'Qual o melhor horário para eu estudar Python hoje?' })
    expect(reply.stored).toBe(true)
    expect(reply.reply).not.toMatch(/<\/?think>/i)
    expect(reply.memories_used.map((m) => m.id)).toContain(memoryId)
    conversationId = reply.conversation_id!

    const next = await nexo.send({ message: 'Anota: meu CPF é 123.456.789-09.', conversation_id: conversationId })
    expect(next.masked_in_history).toBe(1)

    const detail = await conversations.get(conversationId)
    expect(detail.messages.map((m) => m.content).join(' ')).not.toContain('123.456.789-09')
    expect(detail.messages).toHaveLength(4)
  }, 300_000)

  it('conversa privada não é guardada', async () => {
    const before = (await conversations.list()).length
    const reply = await nexo.send({ message: 'Isto é privado.', private: true, history: [] })
    expect(reply.stored).toBe(false)
    expect(reply.conversation_id).toBeNull()
    expect(await conversations.list()).toHaveLength(before)
  }, 300_000)

  it('histórico: busca, renomeia; sugestões podem ser aceitas ou recusadas', async () => {
    expect((await conversations.list('Python')).map((c) => c.id)).toContain(conversationId)
    expect((await conversations.rename(conversationId, 'Estudos de Python')).title).toBe('Estudos de Python')

    // Sugestões dependem do modelo real: o contrato é a forma, não a quantidade.
    const pending = await suggestions.list()
    for (const s of pending) expect(s.source.conversation_id).toBe(conversationId)
    if (pending[0]) expect((await suggestions.accept(pending[0].id)).origin).toBe('suggestion_confirmed')
    if (pending[1]) await suggestions.reject(pending[1].id)
  })

  it('Meus dados: inventário só com contagens da própria conta', async () => {
    const summary = await myData.summary()
    expect(summary.conversations.count).toBeGreaterThanOrEqual(1)
    expect(summary.memories.count).toBeGreaterThanOrEqual(1)
    expect(summary.planning).toMatchObject({ routine_blocks: 1, goals: 1, goals_active: 1 })
    expect(summary.security_log.retention_days).toBe(365)
    expect(JSON.stringify(summary)).not.toContain('Python')
  })

  it('privacidade: retenção com prévia, revogação exige escolha, exportação de uso único', async () => {
    expect((await preferences.previewRetention(30)).conversations_to_delete).toBe(0)
    expect((await preferences.setRetention(30)).history_retention_days).toBe(30)

    await expectApiError(consents.revoke('nexo.memory'), 422)
    await consents.revoke('nexo.memory', 'delete')
    expect(await memories.list()).toEqual([])

    const requested = await exports.request(password)
    let status = requested.status
    for (let i = 0; i < 20 && status === 'pending'; i++) status = (await exports.get(requested.id)).status
    expect(status).toBe('ready')
    const blob = await exports.download(requested.id)
    expect(blob.size).toBeGreaterThan(100)
    await expectApiError(exports.download(requested.id), 410)
  })

  it('sair, entrar de novo e excluir a conta', async () => {
    await auth.logout()
    clearCookies()
    await expectApiError(auth.me(), 401)
    await auth.login(email, password)
    await expectApiError(auth.deleteAccount('senhaErrada123'), 422)
    await auth.deleteAccount(password)
    await expectApiError(auth.me(), 401)
  })
})
