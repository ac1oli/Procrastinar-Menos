/** Tipos dos recursos da API (backend/README.md, ADR-0008). */

export interface Data<T> {
  data: T
}

export interface User {
  id: string
  name: string
  email: string
  timezone: string
  created_at: string
}

export type ConsentScopeId =
  | 'nexo.history'
  | 'nexo.memory'
  | 'nexo.memory.suggestions'
  | 'nexo.memory.sensitive'
  | 'nexo.context.routine'
  | 'nexo.context.goals'
  | 'nexo.context.activity'

export interface ConsentScope {
  scope: ConsentScopeId
  /** null = nunca decidido (o onboarding pergunta, sem opção pré-marcada). */
  granted: boolean | null
  decided_at: string | null
  requires: ConsentScopeId[]
  holds_data: boolean
  available: boolean
}

export interface ConsentState {
  policy_version: string
  scopes: ConsentScope[]
  pending: ConsentScopeId[]
}

export interface ConsentEvent {
  scope: ConsentScopeId
  action: 'granted' | 'revoked' | 'declined'
  policy_version: string | null
  data_action: 'delete' | 'keep' | null
  created_at: string
}

export type DataAction = 'delete' | 'keep'

export interface Turn {
  role: 'user' | 'assistant'
  content: string
}

export interface UsedMemory {
  id: string
  kind: MemoryKind
  content: string
}

export interface NexoReply {
  reply: string
  model: string
  prompt_version: string
  private: boolean
  stored: boolean
  conversation_id: string | null
  masked_in_history: number | null
  memories_used: UsedMemory[]
}

export interface ConversationSummary {
  id: string
  title: string
  messages_count: number
  last_message_at: string
  expires_at: string | null
}

export interface ConversationMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  masked: number
  created_at: string
}

export interface ConversationDetail extends ConversationSummary {
  memories_from_this_conversation: string[]
  messages: ConversationMessage[]
}

export type MemoryKind = 'preferencia' | 'objetivo_pessoal' | 'conhecimento' | 'padrao' | 'contexto_de_vida' | 'restricao'
export type MemoryStatus = 'active' | 'needs_review' | 'archived'

export interface Memory {
  id: string
  kind: MemoryKind
  content: string
  sensitivity: 'normal' | 'sensitive'
  origin: 'user_created' | 'suggestion_confirmed' | 'imported' | 'agent_observed'
  evidence_level: 'observada' | 'declarada' | 'consultada' | 'praticada' | 'demonstrada'
  confidence: 'alta' | 'media' | 'baixa'
  status: MemoryStatus
  pinned: boolean
  source: { conversation_id: string | null; message_id: string | null }
  semantic_index: 'ready' | 'pending'
  created_at: string
  confirmed_at: string | null
  last_used_at: string | null
  last_reviewed_at: string | null
  review_due_at: string | null
}

export interface MemoryInput {
  kind: MemoryKind
  content: string
  sensitive?: boolean
  pinned?: boolean
}

export interface MemorySuggestion {
  id: string
  kind: MemoryKind
  content: string
  rationale: string
  source: { conversation_id: string; message_id: string }
  created_at: string
  expires_at: string
}

export interface NexoPreferences {
  history_retention_days: number | null
  history_retention_options: (number | null)[]
}

export interface DataExport {
  id: string
  status: 'pending' | 'ready' | 'failed' | 'downloaded'
  expires_at: string
  downloaded_at: string | null
}

export type ActivityCategory = 'study' | 'work' | 'leisure' | 'rest' | 'commitment' | 'other'

export interface RoutineBlock {
  id: string
  title: string
  category: ActivityCategory
  days_of_week: number[]
  start_time: string
  end_time: string
  crosses_midnight: boolean
  notes: string | null
  is_active: boolean
  updated_at: string
}

export type RoutineBlockInput = Pick<RoutineBlock, 'title' | 'category' | 'days_of_week' | 'start_time' | 'end_time' | 'is_active'> & {
  notes?: string | null
}

export type GoalType = 'personal' | 'professional' | 'learning'
export type GoalStatus = 'active' | 'paused' | 'completed' | 'abandoned'

export interface Goal {
  id: string
  title: string
  description: string | null
  type: GoalType
  status: GoalStatus
  target_date: string | null
  created_at: string
  updated_at: string
}

export type GoalInput = { title: string; type: GoalType; description?: string | null; status?: GoalStatus; target_date?: string | null }
