/** Textos exibidos à pessoa. Só apresentação: as regras ficam no backend. */
import type {
  ActivityCategory,
  ConsentScopeId,
  GoalStatus,
  GoalType,
  Memory,
  MemoryKind,
  MemoryStatus,
} from '@/api/types'

export const scopeInfo: Record<ConsentScopeId, { title: string; description: string; holdsDataNote?: string }> = {
  'nexo.history': {
    title: 'Guardar o histórico de conversas',
    description:
      'As conversas ficam salvas para você retomar depois. Antes de gravar, documentos, cartões, contatos e senhas são mascarados. Prazo padrão: 90 dias, ajustável.',
    holdsDataNote: 'conversas guardadas',
  },
  'nexo.memory': {
    title: 'Memória de longo prazo',
    description:
      'O NEXO usa informações que você criar ou confirmar (preferências, objetivos, conhecimentos) para personalizar as respostas. Nada é guardado sem a sua confirmação.',
    holdsDataNote: 'memórias',
  },
  'nexo.memory.suggestions': {
    title: 'Sugestões de memória',
    description:
      'O NEXO pode propor memórias a partir das conversas guardadas. Cada sugestão espera a sua confirmação e expira em 14 dias. Exige memória e histórico.',
  },
  'nexo.memory.sensitive': {
    title: 'Memórias sensíveis',
    description:
      'Permite guardar, quando você mesmo criar, informações sobre saúde, religião, política, vida sexual, origem étnica, filiação sindical, genética ou biometria. O NEXO nunca sugere esse tipo de memória.',
    holdsDataNote: 'memórias sensíveis',
  },
  'nexo.context.routine': {
    title: 'Usar a sua rotina nas respostas',
    description: 'O NEXO recebe só os blocos planejados para hoje (título, horário e categoria), sem observações.',
  },
  'nexo.context.goals': {
    title: 'Usar os seus objetivos nas respostas',
    description: 'O NEXO recebe até 3 objetivos ativos (título, tipo e prazo), sem descrições.',
  },
  'nexo.context.activity': {
    title: 'Atividade dos dispositivos',
    description: 'Disponível quando os agentes de computador e celular existirem (FASE 5).',
  },
}

export const kindLabel: Record<MemoryKind, string> = {
  preferencia: 'Preferência',
  objetivo_pessoal: 'Objetivo pessoal',
  conhecimento: 'Conhecimento',
  padrao: 'Padrão',
  contexto_de_vida: 'Contexto de vida',
  restricao: 'Restrição',
}

export const statusLabel: Record<MemoryStatus, string> = {
  active: 'Ativa',
  needs_review: 'Para revisar',
  archived: 'Arquivada',
}

export const originLabel: Record<Memory['origin'], string> = {
  user_created: 'Criada por você',
  suggestion_confirmed: 'Sugestão confirmada',
  imported: 'Importada',
  agent_observed: 'Observada por agente',
}

export const evidenceLabel: Record<Memory['evidence_level'], string> = {
  observada: 'Observada',
  declarada: 'Declarada',
  consultada: 'Consultada',
  praticada: 'Praticada',
  demonstrada: 'Demonstrada',
}

export const confidenceLabel: Record<Memory['confidence'], string> = {
  alta: 'Confiança alta',
  media: 'Confiança média',
  baixa: 'Confiança baixa',
}

export const categoryLabel: Record<ActivityCategory, string> = {
  study: 'Estudo',
  work: 'Trabalho',
  leisure: 'Lazer',
  rest: 'Descanso',
  commitment: 'Compromisso',
  other: 'Outro',
}

export const goalTypeLabel: Record<GoalType, string> = {
  personal: 'Pessoal',
  professional: 'Profissional',
  learning: 'Aprendizagem',
}

export const goalStatusLabel: Record<GoalStatus, string> = {
  active: 'Ativo',
  paused: 'Pausado',
  completed: 'Concluído',
  abandoned: 'Abandonado',
}

/** Dias ISO-8601 (1 = segunda … 7 = domingo), como no backend. */
export const weekdays = [
  { iso: 1, short: 'Seg', long: 'Segunda' },
  { iso: 2, short: 'Ter', long: 'Terça' },
  { iso: 3, short: 'Qua', long: 'Quarta' },
  { iso: 4, short: 'Qui', long: 'Quinta' },
  { iso: 5, short: 'Sex', long: 'Sexta' },
  { iso: 6, short: 'Sáb', long: 'Sábado' },
  { iso: 7, short: 'Dom', long: 'Domingo' },
]

export const aiFailureLabel: Record<string, string> = {
  unavailable: 'O serviço de IA está fora do ar.',
  timeout: 'O modelo demorou demais para responder.',
  busy: 'O NEXO está atendendo outras mensagens.',
  invalid_response: 'O modelo devolveu uma resposta inválida.',
  misconfigured: 'Há um problema de configuração no servidor.',
}

export function retentionLabel(days: number | null): string {
  return days === null ? 'Até eu excluir' : `${days} dias`
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/**
 * Data em pt-BR no fuso da conta (o mesmo que o NEXO usa); sem fuso, o do navegador.
 * Datas sem hora ("2026-12-01", como prazos) são dias de calendário: nunca mudam de dia
 * por causa do fuso (antes, no Brasil, apareciam como o dia anterior).
 */
export function formatDate(iso: string | null | undefined, withTime = false, timeZone?: string): string {
  if (!iso) return '—'
  if (DATE_ONLY.test(iso)) {
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${iso}T00:00:00Z`))
  }
  const options: Intl.DateTimeFormatOptions = withTime ? { dateStyle: 'short', timeStyle: 'short' } : { dateStyle: 'medium' }
  return new Intl.DateTimeFormat('pt-BR', { ...options, timeZone }).format(new Date(iso))
}

/** "-03:00" → "UTC−03:00" */
export function formatOffset(offset: string): string {
  return `UTC${offset.replace('-', '−')}`
}

/** Dia ISO de hoje no fuso da pessoa (o mesmo critério do backend). */
export function todayIso(timezone: string, now = new Date()): number {
  const day = new Intl.DateTimeFormat('en-US', { timeZone: timezone, weekday: 'short' }).format(now)
  return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(day) + 1
}
