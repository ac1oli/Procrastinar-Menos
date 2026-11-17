# ADR-0006 — Retenção de auditoria e exclusão de conta

- **Status:** aceita
- **Data:** 2026-11-17

## Contexto
O proprietário definiu que a exclusão de conta deve preservar só os registros de
auditoria estritamente necessários, desvinculados da pessoa e sem dados pessoais
desnecessários. Até aqui, `audit_logs.user_id` virava `NULL` por `nullOnDelete`, mas o IP
e os metadados permaneciam, e tokens de dispositivos (polimórficos, sem FK) ficavam órfãos.

## Decisão
1. Exclusão via ação `DeleteAccount`, em transação:
   tokens (usuário e dispositivos) → sessões → auditoria (filtra e anonimiza) → usuário
   (cascata) → evento `account.deleted` sem identificadores.
2. Lista explícita de eventos retidos em `config/retention.php`. O restante é apagado.
3. Anonimização total dos retidos: `user_id`, `ip_address`, `metadata` e `subject_id` = `NULL`.
4. Prazos gerais: IP apagado após 90 dias; eventos excluídos após 365 dias
   (`audit:apply-retention`, diário).
5. Exclusão exige reconfirmação de senha e encerra a sessão **antes** de excluir.
6. Política descrita em [retencao-de-dados.md](../security/retencao-de-dados.md).

## Alternativas avaliadas
- **Manter todos os eventos com `user_id` nulo:** o IP e o `subject_id` ainda permitiriam
  reidentificar e relacionar eventos. Rejeitado.
- **Apagar toda a auditoria do usuário:** impede demonstrar o cumprimento da exclusão e
  perde estatística de segurança. Rejeitado.
- **Pseudonimizar com hash do user_id:** um hash estável continua vinculando os eventos e
  pode ser revertido por força bruta (UUIDs são enumeráveis no próprio banco). Rejeitado.

## Consequências
- Não dá para investigar incidentes de uma conta já excluída. Aceito, por privacidade.
- Descoberto durante a implementação: o driver de sessão em banco gravava a sessão nova
  com o `user_id` do usuário excluído, porque o guard do Sanctum (padrão nas rotas
  `auth:sanctum`) mantém o usuário em cache. Corrigido em `AuthController::endSession`
  (também afeta o logout comum), com teste de regressão que falha sem a correção.
- Novos tipos de evento precisam ser classificados (reter ou não) ao serem criados.
