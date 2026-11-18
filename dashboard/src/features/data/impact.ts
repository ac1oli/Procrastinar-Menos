import { useQuery } from '@tanstack/react-query'

import { myData } from '@/api/endpoints'
import { keys } from '@/api/queries'
import type { DataSummary } from '@/api/types'

/** Inventário da conta (contagens do backend), usado nas prévias de exclusão e em "Meus dados". */
export function useDataSummary(enabled = true) {
  return useQuery({ queryKey: keys.dataSummary, queryFn: myData.summary, enabled })
}

export function historyImpact(s: DataSummary) {
  return {
    removed: [
      `${s.conversations.count} conversa(s) guardada(s), com ${s.conversations.messages} mensagem(ns)`,
      `${s.suggestions.pending} sugestão(ões) de memória pendente(s) nascida(s) dessas conversas`,
    ],
    kept: [
      `${s.memories.count} memória(s)${s.memories.from_conversations ? ` (${s.memories.from_conversations} perdem só o vínculo com a conversa de origem)` : ''}`,
      'rotina, objetivos, autorizações e perfil',
    ],
  }
}

export function memoriesImpact(s: DataSummary) {
  return {
    removed: [
      `${s.memories.count} memória(s): ${s.memories.active} ativa(s), ${s.memories.needs_review} para revisar, ${s.memories.archived} arquivada(s)`,
      `inclui ${s.memories.pinned} fixada(s) e ${s.memories.sensitive} sensível(is), com os índices de busca`,
    ],
    kept: [`${s.conversations.count} conversa(s) e ${s.suggestions.pending} sugestão(ões) pendente(s)`, 'rotina, objetivos, autorizações e perfil'],
  }
}

export function accountImpact(s: DataSummary) {
  return {
    removed: [
      'perfil (nome, e-mail, fuso) e senha',
      `${s.conversations.count} conversa(s) com ${s.conversations.messages} mensagem(ns)`,
      `${s.memories.count} memória(s) e ${s.suggestions.pending} sugestão(ões) pendente(s)`,
      `${s.planning.routine_blocks} bloco(s) de rotina e ${s.planning.goals} objetivo(s)`,
      `${s.devices.active + s.devices.revoked} dispositivo(s) e suas credenciais`,
      `autorizações e o histórico de ${s.consents.events} decisão(ões)`,
      `${s.security_log.events - s.security_log.kept_anonymized_on_deletion} registro(s) de atividade da conta`,
    ],
    kept: [
      `${s.security_log.kept_anonymized_on_deletion} registro(s) de segurança anonimizado(s) (sem usuário, IP ou detalhes), apagados em até ${s.security_log.retention_days} dias`,
    ],
  }
}
