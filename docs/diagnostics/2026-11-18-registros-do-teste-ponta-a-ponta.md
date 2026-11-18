# Registros remanescentes do teste ponta a ponta da memória (2026-11-18)

**Decisão do proprietário:** os registros **não serão apagados**. Seguem os prazos normais de
retenção. Este documento registra a verificação feita antes dessa decisão.

## Origem

O teste ponta a ponta de 2026-11-17 (12:03–12:04 UTC) criou uma conta fictícia
(`e2e-memoria+…@example.com`, nome "Pessoa Fictícia E2E"). O teste exerceu memórias, conversas,
sugestões e exportação e terminou excluindo a conta pela própria API (`DELETE /api/me`).

## O que restou no banco principal (verificado por consulta somente leitura)

| Tabela | Registros | Conteúdo | Dado pessoal real? |
|---|---|---|---|
| `users` e todas as tabelas do NEXO | 0 | — | — |
| `audit_logs` | 2 (`auth.registered`, `account.deleted`) | Só ação e data; `user_id`, IP, metadados e objeto nulos | Não |
| `deletion_journal` | 11 (1 conta, 1 conversa, 4 mensagens, 3 memórias, 2 sugestões) | Só tipo, UUID e data; todos do mesmo `user_id` fictício | Não |
| `sessions` | 3, sem usuário | IP do gateway Docker (`172.18.0.1`) e agente do PowerShell | Não |
| `embedding_models` | 1 (`qwen3-embedding:0.6b`) | Registro técnico do modelo | Não |

- Nenhuma conta real jamais existiu no banco principal. Todas as contas foram de testes de
  fumaça com e-mails fictícios (`@teste.local`, `@example.com`).
- Os UUIDs do diário são aleatórios (v7) e não se relacionam com nenhuma pessoa.

## Interferência nas operações normais

Nenhuma:
- **Diário:** não tem chaves estrangeiras. Numa restauração, a reaplicação dessas entradas não
  encontra linhas correspondentes e não exclui nada.
- **Auditoria:** registros anonimizados não aparecem para nenhum usuário.
- **Sessões:** são anônimas e expiram pelo coletor do Laravel (120 min, limpeza por sorteio).

## Prazos

| Registro | Expira em |
|---|---|
| Diário de exclusões | 35 dias, por `purge_deletion_journal` via `nexo:apply-retention`, em ~2026-12-22 |
| Auditoria | 365 dias, por `audit:apply-retention`, em ~2027-11-17 |
| Sessões anônimas | Coleta automática do Laravel |
