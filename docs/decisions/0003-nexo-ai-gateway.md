# ADR-0003 — NEXO AI Gateway e memória fora do modelo

- **Status:** aceita
- **Data:** 2026-11-16

## Contexto
O NEXO precisa funcionar com um modelo local hoje e possivelmente com
provedores em nuvem amanhã, sem reescrever regras de negócio. A memória
do assistente (perfil, preferências, decisões, feedback) não pode se
perder ao trocar de modelo. Recursos básicos (rotinas, regras de foco)
não podem depender da IA.

## Decisão
Duas camadas de abstração:

1. **No backend (Laravel):** interface `NexoGateway` com operações de
   domínio (`chat`, `summarize`, `classifyActivity`, `embed`,
   `generateQuestions`…). A implementação padrão chama o `ai-service` por
   HTTP. O restante do backend só conhece essa interface.
2. **No ai-service (FastAPI):** interface `ModelProvider` (`generate`,
   `chat`, `embed`) com adaptador `OllamaProvider`. Novos provedores
   (nuvem) entram como adaptadores adicionais, selecionados por configuração.

**Memória:** toda memória do NEXO é persistida no PostgreSQL pelo backend
(tabelas próprias + embeddings em pgvector), com retenção, consulta e
exclusão controladas pelo usuário. O ai-service recebe o contexto
necessário em cada requisição e não guarda estado de usuário.

**Degradação:** se o ai-service ou o Ollama estiverem indisponíveis, o
gateway retorna erro tipado (`AiUnavailable`) e as funções não-IA
continuam operando normalmente.

## Alternativas avaliadas
- **Laravel chamando o Ollama direto** — acopla negócio ao provedor. Rejeitado.
- **Memória em framework de agentes no Python** — torna o ai-service
  stateful e dificulta isolamento multiusuário/LGPD. Rejeitado.

## Consequências
- Toda requisição de IA carrega `user_id` e escopo de contexto autorizado,
  validados no backend.
- Embeddings têm dimensão fixa por modelo; trocar o modelo de embedding
  exige reindexação (registrar o modelo usado em cada vetor).
