/** Funções finas sobre a API real. Cada uma corresponde a uma rota do backend. */
import { api, apiBlob, ensureCsrfCookie } from './client'
import type {
  ConsentEvent,
  ConsentScopeId,
  ConsentState,
  ConversationDetail,
  ConversationSummary,
  Data,
  DataAction,
  DataExport,
  Goal,
  GoalInput,
  Memory,
  MemoryInput,
  MemoryStatus,
  MemorySuggestion,
  NexoPreferences,
  NexoReply,
  RoutineBlock,
  RoutineBlockInput,
  Turn,
  User,
} from './types'

const data = <T>(p: Promise<Data<T>>) => p.then((r) => r.data)
const qs = (params: Record<string, string | number | undefined | null>) => {
  const s = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '') as [string, string][])
  return s.size ? `?${s}` : ''
}

export const auth = {
  me: () => data(api<Data<User>>('/me')),
  async login(email: string, password: string) {
    await ensureCsrfCookie()
    return data(api<Data<User>>('/auth/login', { method: 'POST', body: { email, password } }))
  },
  async register(input: { name: string; email: string; password: string; password_confirmation: string; timezone: string }) {
    await ensureCsrfCookie()
    return data(api<Data<User>>('/auth/register', { method: 'POST', body: input }))
  },
  logout: () => api<void>('/auth/logout', { method: 'POST' }),
  deleteAccount: (password: string) => api<void>('/me', { method: 'DELETE', body: { password } }),
}

export const consents = {
  get: () => data(api<Data<ConsentState>>('/nexo/consents')),
  history: () => data(api<Data<ConsentEvent[]>>('/nexo/consents/history')),
  grant: (scope: ConsentScopeId, policyVersion: string) =>
    data(api<Data<ConsentState>>(`/nexo/consents/${scope}`, { method: 'PUT', body: { granted: true, policy_version: policyVersion } })),
  revoke: (scope: ConsentScopeId, dataAction?: DataAction) =>
    data(api<Data<ConsentState>>(`/nexo/consents/${scope}`, { method: 'PUT', body: { granted: false, data_action: dataAction } })),
}

export const nexo = {
  status: () => data(api<Data<{ available: boolean }>>('/nexo/status')),
  send: (input: { message: string; conversation_id?: string | null; private?: boolean; history?: Turn[] }) =>
    data(api<Data<NexoReply>>('/nexo/messages', { method: 'POST', body: input })),
}

export const conversations = {
  list: (q?: string) => data(api<Data<ConversationSummary[]>>(`/nexo/conversations${qs({ q })}`)),
  get: (id: string) => data(api<Data<ConversationDetail>>(`/nexo/conversations/${id}`)),
  rename: (id: string, title: string) => data(api<Data<ConversationSummary>>(`/nexo/conversations/${id}`, { method: 'PATCH', body: { title } })),
  remove: (id: string, withMemories = false) => api<void>(`/nexo/conversations/${id}${withMemories ? '?with_memories=1' : ''}`, { method: 'DELETE' }),
  removeMessage: (id: string, messageId: string) => api<void>(`/nexo/conversations/${id}/messages/${messageId}`, { method: 'DELETE' }),
  clear: () => data(api<Data<{ deleted_conversations: number }>>('/nexo/conversations', { method: 'DELETE', body: { confirm: true } })),
}

export const memories = {
  list: (filters: { status?: MemoryStatus; q?: string } = {}) => data(api<Data<Memory[]>>(`/nexo/memories${qs(filters)}`)),
  create: (input: MemoryInput) => data(api<Data<Memory>>('/nexo/memories', { method: 'POST', body: input })),
  update: (id: string, input: Partial<MemoryInput> & { status?: 'active' | 'archived' }) =>
    data(api<Data<Memory>>(`/nexo/memories/${id}`, { method: 'PATCH', body: input })),
  review: (id: string) => data(api<Data<Memory>>(`/nexo/memories/${id}/review`, { method: 'POST' })),
  remove: (id: string) => api<void>(`/nexo/memories/${id}`, { method: 'DELETE' }),
  clear: () => data(api<Data<{ deleted_memories: number }>>('/nexo/memories', { method: 'DELETE', body: { confirm: true } })),
}

export const suggestions = {
  list: () => data(api<Data<MemorySuggestion[]>>('/nexo/memory-suggestions')),
  accept: (id: string, edits: Partial<MemoryInput> = {}) =>
    data(api<Data<Memory>>(`/nexo/memory-suggestions/${id}/accept`, { method: 'POST', body: edits })),
  reject: (id: string) => api<void>(`/nexo/memory-suggestions/${id}`, { method: 'DELETE' }),
}

export const preferences = {
  get: () => data(api<Data<NexoPreferences>>('/nexo/preferences')),
  setRetention: (days: number | null) => data(api<Data<NexoPreferences>>('/nexo/preferences', { method: 'PUT', body: { history_retention_days: days } })),
  previewRetention: (days: number) => data(api<Data<{ days: number; conversations_to_delete: number }>>(`/nexo/preferences/retention-preview${qs({ days })}`)),
}

export const exports = {
  request: (password: string) => data(api<Data<DataExport>>('/me/exports', { method: 'POST', body: { password } })),
  get: (id: string) => data(api<Data<DataExport>>(`/me/exports/${id}`)),
  download: (id: string) => apiBlob(`/me/exports/${id}/download`),
}

export const routine = {
  list: () => data(api<Data<RoutineBlock[]>>('/routine-blocks')),
  create: (input: RoutineBlockInput) => data(api<Data<RoutineBlock>>('/routine-blocks', { method: 'POST', body: input })),
  update: (id: string, input: Partial<RoutineBlockInput>) => data(api<Data<RoutineBlock>>(`/routine-blocks/${id}`, { method: 'PATCH', body: input })),
  remove: (id: string) => api<void>(`/routine-blocks/${id}`, { method: 'DELETE' }),
}

export const goals = {
  list: () => data(api<Data<Goal[]>>('/goals')),
  create: (input: GoalInput) => data(api<Data<Goal>>('/goals', { method: 'POST', body: input })),
  update: (id: string, input: Partial<GoalInput>) => data(api<Data<Goal>>(`/goals/${id}`, { method: 'PATCH', body: input })),
  remove: (id: string) => api<void>(`/goals/${id}`, { method: 'DELETE' }),
}
