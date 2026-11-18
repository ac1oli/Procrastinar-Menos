/**
 * Backend falso em memória para os testes (MSW). Reproduz as regras do backend
 * real que a interface precisa respeitar: CSRF, sessão, consentimentos com
 * dependências e escolha explícita ao revogar, modo privado e erros 422/503.
 * Os dados são fictícios.
 */
import { http, HttpResponse, type JsonBodyType, type PathParams } from 'msw'
import { setupServer } from 'msw/node'

import type {
  ConsentScope,
  ConsentScopeId,
  ConversationMessage,
  ConversationSummary,
  Goal,
  Memory,
  MemorySuggestion,
  RoutineBlock,
  Turn,
  User,
} from '@/api/types'

export const POLICY = 'politica-de-memoria@1.0'
const TOKEN = 'xsrf-de-teste'

type Recorded = { method: string; path: string; body: unknown }

interface Db {
  user: User | null
  password: string
  consents: Record<ConsentScopeId, boolean | null>
  aiDown: string | null
  conversations: (ConversationSummary & { messages: ConversationMessage[] })[]
  memories: Memory[]
  suggestions: MemorySuggestion[]
  routine: RoutineBlock[]
  goals: Goal[]
  retention: number | null
  requests: Recorded[]
  replyText: string
  memoriesUsed: { id: string; kind: Memory['kind']; content: string }[]
}

const requires: Record<ConsentScopeId, ConsentScopeId[]> = {
  'nexo.history': [],
  'nexo.memory': [],
  'nexo.memory.suggestions': ['nexo.memory', 'nexo.history'],
  'nexo.memory.sensitive': ['nexo.memory'],
  'nexo.context.routine': [],
  'nexo.context.goals': [],
  'nexo.context.activity': [],
}
const holdsData: ConsentScopeId[] = ['nexo.history', 'nexo.memory', 'nexo.memory.sensitive']

export const fakeUser: User = {
  id: 'u-1',
  name: 'Pessoa Fictícia',
  email: 'ficticia@example.com',
  timezone: 'America/Sao_Paulo',
  created_at: '2026-11-18T10:00:00Z',
}

function freshDb(): Db {
  return {
    user: null,
    password: 'senhaFicticia123',
    consents: Object.fromEntries(Object.keys(requires).map((k) => [k, null])) as Db['consents'],
    aiDown: null,
    conversations: [],
    memories: [],
    suggestions: [],
    routine: [],
    goals: [],
    retention: 90,
    requests: [],
    replyText: 'Resposta fictícia do NEXO.',
    memoriesUsed: [],
  }
}

export let db = freshDb()
export function resetDb() {
  db = freshDb()
}

let seq = 0
const id = (prefix: string) => `${prefix}-${++seq}`
const now = () => new Date('2026-11-18T12:00:00Z').toISOString()

export function memory(overrides: Partial<Memory> = {}): Memory {
  return {
    id: id('m'),
    kind: 'preferencia',
    content: 'Prefere estudar de manhã.',
    sensitivity: 'normal',
    origin: 'user_created',
    evidence_level: 'declarada',
    confidence: 'alta',
    status: 'active',
    pinned: false,
    source: { conversation_id: null, message_id: null },
    semantic_index: 'ready',
    created_at: now(),
    confirmed_at: now(),
    last_used_at: null,
    last_reviewed_at: null,
    review_due_at: '2027-05-17T12:00:00Z',
    ...overrides,
  }
}

/** Conta logada e todas as autorizações decididas (sem onboarding pendente). */
export function signedIn(consents: Partial<Record<ConsentScopeId, boolean>> = {}) {
  db.user = fakeUser
  for (const scope of Object.keys(db.consents) as ConsentScopeId[]) {
    db.consents[scope] = scope === 'nexo.context.activity' ? null : (consents[scope] ?? false)
  }
}

function consentState() {
  const scopes: ConsentScope[] = (Object.keys(requires) as ConsentScopeId[]).map((scope) => ({
    scope,
    granted: db.consents[scope],
    decided_at: db.consents[scope] === null ? null : now(),
    requires: requires[scope],
    holds_data: holdsData.includes(scope),
    available: scope !== 'nexo.context.activity',
  }))
  return {
    policy_version: POLICY,
    scopes,
    pending: scopes.filter((s) => s.granted === null && s.available).map((s) => s.scope),
  }
}

const ok = (body: JsonBodyType, status = 200) => HttpResponse.json(body, { status })
const invalid = (field: string, message: string) => ok({ message, errors: { [field]: [message] } }, 422)
const noContent = () => new HttpResponse(null, { status: 204 })

async function record(request: Request) {
  const url = new URL(request.url)
  let body: unknown = null
  try {
    body = await request.clone().json()
  } catch {
    body = null
  }
  db.requests.push({ method: request.method, path: url.pathname + url.search, body })
  return body as Record<string, unknown>
}

/** Envolve um handler exigindo sessão (401) e, em escrita, o token CSRF (419). */
function guard(handler: (args: { request: Request; params: Record<string, string>; body: Record<string, unknown> }) => Response | Promise<Response>) {
  return async ({ request, params }: { request: Request; params: PathParams }) => {
    const body = await record(request)
    if (request.method !== 'GET' && request.headers.get('X-XSRF-TOKEN') !== TOKEN) return ok({ message: 'CSRF token mismatch.' }, 419)
    if (!db.user) return ok({ message: 'Unauthenticated.' }, 401)
    return handler({ request, params: params as Record<string, string>, body })
  }
}

export const handlers = [
  http.get('/sanctum/csrf-cookie', () => {
    document.cookie = `XSRF-TOKEN=${encodeURIComponent(TOKEN)}; path=/`
    return noContent()
  }),

  http.post('/api/auth/login', async ({ request }) => {
    const body = (await record(request)) as { email: string; password: string }
    if (request.headers.get('X-XSRF-TOKEN') !== TOKEN) return ok({ message: 'CSRF token mismatch.' }, 419)
    if (body.email !== fakeUser.email || body.password !== db.password) return invalid('email', 'E-mail ou senha incorretos.')
    db.user = fakeUser
    return ok({ data: fakeUser })
  }),
  http.post('/api/auth/register', async ({ request }) => {
    const body = (await record(request)) as { name: string; email: string; password: string; password_confirmation: string; timezone: string }
    if (request.headers.get('X-XSRF-TOKEN') !== TOKEN) return ok({ message: 'CSRF token mismatch.' }, 419)
    if (body.password.length < 10) return invalid('password', 'A senha deve ter pelo menos 10 caracteres.')
    db.user = { ...fakeUser, name: body.name, email: body.email, timezone: body.timezone }
    return ok({ data: db.user }, 201)
  }),
  http.post('/api/auth/logout', guard(() => {
    db.user = null
    return noContent()
  })),
  http.get('/api/me', guard(() => ok({ data: db.user }))),
  http.delete('/api/me', guard(({ body }) => {
    if (body.password !== db.password) return invalid('password', 'A senha está incorreta.')
    db.user = null
    return noContent()
  })),

  http.get('/api/nexo/status', guard(() => ok({ data: { available: !db.aiDown } }))),
  http.get('/api/nexo/consents', guard(() => ok({ data: consentState() }))),
  http.get('/api/nexo/consents/history', guard(() => ok({ data: [] }))),
  http.put('/api/nexo/consents/:scope', guard(({ params, body }) => {
    const scope = params.scope as ConsentScopeId
    if (body.granted) {
      if (body.policy_version !== POLICY) return invalid('policy_version', 'A política de memória mudou.')
      if (requires[scope].some((r) => !db.consents[r])) return invalid('scope', 'Antes, autorize os requisitos.')
      db.consents[scope] = true
    } else {
      if (db.consents[scope] && holdsData.includes(scope) && !body.data_action) {
        return invalid('data_action', 'Escolha o que fazer com os dados já guardados.')
      }
      for (const dependent of Object.keys(requires) as ConsentScopeId[]) {
        if (requires[dependent].includes(scope) && db.consents[dependent]) db.consents[dependent] = false
      }
      if (body.data_action === 'delete' && scope === 'nexo.memory') db.memories = []
      if (body.data_action === 'delete' && scope === 'nexo.history') db.conversations = []
      db.consents[scope] = false
    }
    return ok({ data: consentState() })
  })),

  http.post('/api/nexo/messages', guard(({ body }) => {
    if (db.aiDown) return ok({ message: 'O NEXO está indisponível no momento.', reason: db.aiDown }, 503)
    const persist = !body.private && db.consents['nexo.history'] === true
    let conversation = body.conversation_id ? db.conversations.find((c) => c.id === body.conversation_id) : undefined
    if (body.conversation_id && !persist) return ok({ message: 'O histórico está desligado.' }, 409)
    if (persist) {
      if (!conversation) {
        conversation = { id: id('c'), title: String(body.message).slice(0, 80), messages_count: 0, last_message_at: now(), expires_at: '2027-02-16T12:00:00Z', messages: [] }
        db.conversations.unshift(conversation)
      }
      conversation.messages.push(
        { id: id('msg'), role: 'user', content: String(body.message), masked: 0, created_at: now() },
        { id: id('msg'), role: 'assistant', content: db.replyText, masked: 0, created_at: now() },
      )
      conversation.messages_count = conversation.messages.length
    }
    return ok({
      data: {
        reply: db.replyText,
        model: 'fake-model',
        prompt_version: 'nexo-system@test',
        private: !!body.private,
        stored: persist,
        conversation_id: conversation?.id ?? null,
        masked_in_history: persist ? 0 : null,
        memories_used: db.memoriesUsed,
      },
    })
  })),

  http.get('/api/nexo/conversations', guard(({ request }) => {
    const q = new URL(request.url).searchParams.get('q')?.toLowerCase()
    const list = db.conversations.filter((c) => !q || c.messages.some((m) => m.content.toLowerCase().includes(q)))
    return ok({ data: list.map(({ messages, ...summary }) => ({ ...summary, messages_count: messages.length })) })
  })),
  http.get('/api/nexo/conversations/:id', guard(({ params }) => {
    const c = db.conversations.find((x) => x.id === params.id)
    if (!c) return ok({ message: 'Não encontrado.' }, 404)
    return ok({ data: { ...c, memories_from_this_conversation: db.memories.filter((m) => m.source.conversation_id === c.id).map((m) => m.id) } })
  })),
  http.patch('/api/nexo/conversations/:id', guard(({ params, body }) => {
    const c = db.conversations.find((x) => x.id === params.id)
    if (!c) return ok({ message: 'Não encontrado.' }, 404)
    c.title = String(body.title)
    return ok({ data: c })
  })),
  http.delete('/api/nexo/conversations/:id/messages/:messageId', guard(({ params }) => {
    const c = db.conversations.find((x) => x.id === params.id)
    if (!c) return ok({ message: 'Não encontrado.' }, 404)
    c.messages = c.messages.filter((m) => m.id !== params.messageId)
    c.messages_count = c.messages.length
    if (!c.messages.length) db.conversations = db.conversations.filter((x) => x !== c)
    return noContent()
  })),
  http.delete('/api/nexo/conversations/:id', guard(({ params, request }) => {
    if (new URL(request.url).searchParams.get('with_memories')) db.memories = db.memories.filter((m) => m.source.conversation_id !== params.id)
    db.conversations = db.conversations.filter((x) => x.id !== params.id)
    return noContent()
  })),
  http.delete('/api/nexo/conversations', guard(({ body }) => {
    if (!body.confirm) return invalid('confirm', 'Confirme.')
    const deleted = db.conversations.length
    db.conversations = []
    return ok({ data: { deleted_conversations: deleted } })
  })),

  http.get('/api/nexo/memories', guard(({ request }) => {
    const params = new URL(request.url).searchParams
    const status = params.get('status')
    const q = params.get('q')?.toLowerCase()
    return ok({ data: db.memories.filter((m) => (!status || m.status === status) && (!q || m.content.toLowerCase().includes(q))) })
  })),
  http.post('/api/nexo/memories', guard(({ body }) => {
    if (!db.consents['nexo.memory']) return ok({ message: 'A memória de longo prazo está desligada.' }, 409)
    const content = String(body.content)
    if (/\d{3}\.\d{3}\.\d{3}-\d{2}/.test(content)) return invalid('content', 'Memórias não podem conter documentos, cartões, contatos, senhas ou outras credenciais.')
    const m = memory({ kind: body.kind as Memory['kind'], content, pinned: !!body.pinned, sensitivity: body.sensitive ? 'sensitive' : 'normal' })
    db.memories.unshift(m)
    return ok({ data: m }, 201)
  })),
  http.patch('/api/nexo/memories/:id', guard(({ params, body }) => {
    const m = db.memories.find((x) => x.id === params.id)
    if (!m) return ok({ message: 'Não encontrado.' }, 404)
    Object.assign(m, body.content ? { content: body.content } : {}, body.kind ? { kind: body.kind } : {}, body.status ? { status: body.status } : {}, 'pinned' in body ? { pinned: body.pinned } : {})
    return ok({ data: m })
  })),
  http.post('/api/nexo/memories/:id/review', guard(({ params }) => {
    const m = db.memories.find((x) => x.id === params.id)
    if (!m) return ok({ message: 'Não encontrado.' }, 404)
    Object.assign(m, { status: 'active', last_reviewed_at: now() })
    return ok({ data: m })
  })),
  http.delete('/api/nexo/memories/:id', guard(({ params }) => {
    db.memories = db.memories.filter((x) => x.id !== params.id)
    return noContent()
  })),
  http.delete('/api/nexo/memories', guard(() => {
    const deleted = db.memories.length
    db.memories = []
    return ok({ data: { deleted_memories: deleted } })
  })),

  http.get('/api/nexo/memory-suggestions', guard(() => ok({ data: db.suggestions }))),
  http.post('/api/nexo/memory-suggestions/:id/accept', guard(({ params, body }) => {
    const s = db.suggestions.find((x) => x.id === params.id)
    if (!s) return ok({ message: 'Não encontrado.' }, 404)
    const content = String(body.content ?? s.content)
    if (/ansiedade|tdah/i.test(content)) return invalid('content', 'Dados sensíveis não entram por sugestão.')
    db.suggestions = db.suggestions.filter((x) => x !== s)
    const m = memory({ kind: (body.kind as Memory['kind']) ?? s.kind, content, origin: 'suggestion_confirmed' })
    db.memories.unshift(m)
    return ok({ data: m }, 201)
  })),
  http.delete('/api/nexo/memory-suggestions/:id', guard(({ params }) => {
    db.suggestions = db.suggestions.filter((x) => x.id !== params.id)
    return noContent()
  })),

  http.get('/api/nexo/preferences', guard(() => ok({ data: { history_retention_days: db.retention, history_retention_options: [30, 90, 365, null] } }))),
  http.put('/api/nexo/preferences', guard(({ body }) => {
    db.retention = body.history_retention_days as number | null
    return ok({ data: { history_retention_days: db.retention, history_retention_options: [30, 90, 365, null] } })
  })),
  http.get('/api/nexo/preferences/retention-preview', guard(({ request }) => {
    const days = Number(new URL(request.url).searchParams.get('days'))
    return ok({ data: { days, conversations_to_delete: days < 90 ? db.conversations.length : 0 } })
  })),

  http.post('/api/me/exports', guard(({ body }) => {
    if (body.password !== db.password) return invalid('password', 'A senha está incorreta.')
    return ok({ data: { id: 'e-1', status: 'pending', expires_at: '2026-11-19T12:00:00Z', downloaded_at: null } }, 202)
  })),
  http.get('/api/me/exports/:id', guard(() => ok({ data: { id: 'e-1', status: 'ready', expires_at: '2026-11-19T12:00:00Z', downloaded_at: null } }))),
  http.get('/api/me/exports/:id/download', guard(() => new HttpResponse(new Blob(['PK-ficticio']), { headers: { 'Content-Type': 'application/zip' } }))),

  http.get('/api/routine-blocks', guard(() => ok({ data: db.routine }))),
  http.post('/api/routine-blocks', guard(({ body }) => {
    if (!(body.days_of_week as number[])?.length) return invalid('days_of_week', 'Escolha pelo menos um dia.')
    const block = { id: id('r'), crosses_midnight: String(body.end_time) < String(body.start_time), notes: null, updated_at: now(), ...body } as RoutineBlock
    db.routine.push(block)
    return ok({ data: block }, 201)
  })),
  http.patch('/api/routine-blocks/:id', guard(({ params, body }) => {
    const b = db.routine.find((x) => x.id === params.id)!
    Object.assign(b, body)
    return ok({ data: b })
  })),
  http.delete('/api/routine-blocks/:id', guard(({ params }) => {
    db.routine = db.routine.filter((x) => x.id !== params.id)
    return noContent()
  })),

  http.get('/api/goals', guard(() => ok({ data: db.goals }))),
  http.post('/api/goals', guard(({ body }) => {
    if (!body.title) return invalid('title', 'O título é obrigatório.')
    const goal = { id: id('g'), status: 'active', description: null, target_date: null, created_at: now(), updated_at: now(), ...body } as Goal
    db.goals.push(goal)
    return ok({ data: goal }, 201)
  })),
  http.patch('/api/goals/:id', guard(({ params, body }) => {
    const g = db.goals.find((x) => x.id === params.id)!
    Object.assign(g, body)
    return ok({ data: g })
  })),
  http.delete('/api/goals/:id', guard(({ params }) => {
    db.goals = db.goals.filter((x) => x.id !== params.id)
    return noContent()
  })),
]

export const server = setupServer(...handlers)

/** Requisições de escrita feitas a um caminho (para conferir o que a tela enviou). */
export function sent(method: string, pathPrefix: string): unknown[] {
  return db.requests.filter((r) => r.method === method && r.path.startsWith(pathPrefix)).map((r) => r.body)
}

export type { Turn }
