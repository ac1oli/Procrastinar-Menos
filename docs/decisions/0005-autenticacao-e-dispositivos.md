# ADR-0005 — Autenticação de usuários e dispositivos

- **Status:** aceita
- **Data:** 2026-11-17

## Contexto
Dois tipos de cliente acessam o backend: o dashboard web (pessoa) e os
agentes (Windows, Android, extensão). Eles têm riscos diferentes: tokens no
navegador são expostos a XSS, e tokens de agentes vivem em máquinas que
podem ser perdidas. O sistema é multiusuário desde o início e sincroniza
dados entre dispositivos.

## Decisão
1. **Dashboard:** Sanctum em modo SPA, com **sessão em cookie HttpOnly** e
   proteção CSRF (`/sanctum/csrf-cookie` + `X-XSRF-TOKEN`). Nenhum token no
   `localStorage`. Origens permitidas em `SANCTUM_STATEFUL_DOMAINS`.
2. **Dispositivos:** cada `Device` é dono (tokenable) do próprio token
   Sanctum, com escopos mínimos (`device:sync`, `rules:read`,
   `events:write`). O token em claro aparece só na resposta de vínculo;
   o banco guarda apenas o hash.
3. **Separação de credenciais:** o middleware `actor:user` / `actor:device`
   impede que um token de dispositivo acesse rotas de usuário e vice-versa.
4. **Revogação:** apaga os tokens, marca `revoked_at` e desliga o
   monitoramento; o registro fica para histórico e auditoria.
5. **Privacidade por padrão:** `monitoring_enabled = false` ao vincular;
   cada mudança de consentimento é auditada.
6. **Identificadores UUID** em todas as tabelas (geração offline nos
   agentes, sem enumeração sequencial). Recurso de outro usuário → **404**.
7. **Rate limiting:** 5/min por e-mail+IP em login/registro; 120/min por ator na API.

## Alternativas avaliadas
- **Tokens Bearer também no dashboard** — mais simples, mas expostos a XSS. Rejeitado.
- **Tokens de dispositivo pertencentes ao User** — o servidor não saberia
  *qual* dispositivo fez a chamada, e um token vazado teria o poder do usuário. Rejeitado.
- **OAuth2 (Passport)** — complexidade sem necessidade enquanto não há clientes de terceiros.

## Consequências
- Agentes precisam de um fluxo de pareamento (hoje: o dashboard cria o
  dispositivo e o usuário copia o token). Um pareamento por código curto
  deve substituí-lo na FASE 5.
- ~~Retenção de `audit_logs` após exclusão de conta~~ → resolvida no
  [ADR-0006](0006-retencao-e-exclusao-de-conta.md).

## Atualização 2026-11-17 — ciclo de vida dos tokens
- **Expiração:** `DEVICE_TOKEN_TTL_DAYS` (padrão 90; `0` desativa). O Sanctum rejeita
  tokens vencidos; `devices.token_expires_at` espelha a validade para listagens.
- **Um token ativo por dispositivo:** `Device::issueToken()` apaga os anteriores na
  mesma transação.
- **Renovação pelo agente:** `POST /api/device/token/rotate` devolve token novo e invalida
  o atual. O heartbeat (`GET /api/device/me`) informa `token_renewal_due` nos últimos
  `DEVICE_TOKEN_RENEWAL_WINDOW_DAYS` (padrão 15) dias.
- **Reemissão pelo usuário:** `POST /api/devices/{id}/token` (token perdido ou expirado;
  bloqueada para dispositivo revogado). Limitada pelo throttle de autenticação.
- **Revogação:** apaga tokens e zera `token_expires_at`.
- **Limpeza:** `sanctum:prune-expired --hours=24`, diário.
- Rotação e reemissão são auditadas (`device.token_rotated`, `device.token_reissued`).
- O **pareamento por código curto continua na FASE 5**, fora deste escopo.
