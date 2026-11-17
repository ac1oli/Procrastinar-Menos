# Política de retenção e exclusão de dados

- **Versão:** 1.0 — 2026-11-17
- **Implementação:** `backend/config/retention.php`, `DeleteAccount`, `audit:apply-retention`
- **Decisão:** [ADR-0006](../decisions/0006-retencao-e-exclusao-de-conta.md)

## 1. Princípios (LGPD)

- **Necessidade e minimização (art. 6º, III):** guardar só o indispensável, pelo menor tempo.
- **Eliminação a pedido (art. 18, VI):** a pessoa pode excluir a conta e os dados a qualquer momento.
- **Exceção de guarda (art. 16):** dados podem ser mantidos para cumprir obrigação legal ou
  para segurança, desde que **anonimizados** sempre que possível.
- **Prestação de contas (art. 6º, X):** o sistema deve conseguir demonstrar que cumpriu
  uma exclusão sem manter quem a pediu.

## 2. Tabela de retenção

| Dado | Enquanto a conta existe | Na exclusão da conta |
|---|---|---|
| Perfil (nome, e-mail, senha com hash, fuso) | Mantido | **Excluído** |
| Rotinas, objetivos | Mantido | **Excluído** (cascata) |
| Dispositivos e consentimentos | Mantido | **Excluído** (cascata) |
| Tokens de usuário e de dispositivos | Até expirar/revogar; expirados são apagados após 24 h | **Excluídos** |
| Sessões | Até o logout/expiração | **Excluídas** |
| Conversas e memórias do NEXO | **Não armazenadas** ainda; regras propostas na [política de memória](politica-de-memoria.md) (ADR-0008, aceita; implementação em andamento) | — |
| Auditoria: IP | 90 dias, depois apagado | **Apagado** |
| Auditoria: evento | 365 dias, depois excluído | Ver seção 3 |

## 3. Auditoria na exclusão de conta

Permanecem **apenas** os eventos necessários à segurança e à prestação de contas,
listados em `retained_on_account_deletion`:

`auth.registered`, `auth.login`, `device.linked`, `device.revoked`,
`device.token_reissued`, `device.token_rotated`, `device.monitoring_enabled`,
`device.monitoring_disabled`, `account.deleted`.

Esses registros ficam **anonimizados**: `user_id`, `ip_address`, `metadata` e
`subject_id` viram `NULL`. Resta somente o tipo de evento, o tipo do objeto e a data.
Os demais eventos (ex.: `auth.logout`) são **excluídos**.

Um evento `account.deleted`, também sem identificadores, registra que a exclusão ocorreu.
Todos seguem o prazo geral de 365 dias.

**Justificativa:** sem `user_id`, IP ou identificadores de objeto, não é possível
relacionar os registros à pessoa nem entre si. Eles servem apenas para estatística de
segurança (ex.: volume de vínculos e revogações) e para demonstrar que a exclusão foi
feita.

## 4. Execução

- **Exclusão de conta:** `DELETE /api/me` com reconfirmação de senha. É irreversível e
  roda em transação única. A sessão é encerrada antes, para que nenhuma sessão nova seja
  gravada com o `user_id` excluído (há teste de regressão).
- **Rotina diária:** às 03:00 `audit:apply-retention` (IPs > 90 dias, eventos > 365 dias);
  às 03:10 `sanctum:prune-expired --hours=24`. Executadas pelo container `pm-scheduler`.

## 5. Pendências conhecidas

- Exportação dos dados (portabilidade, art. 18, V) — FASE 4, painel "Meus dados".
- Backups: quando existirem, precisarão de prazo próprio, já que dados excluídos podem
  persistir em backups até a rotação deles.
- Os prazos (90 e 365 dias) são uma escolha técnica conservadora e devem ser revisados
  juridicamente antes do uso comercial (FASE 6).
