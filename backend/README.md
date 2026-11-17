# backend — Laravel 13 (PHP 8.4, em container)

> **Status:** fundação implementada e testada (FASE 3), com o NEXO AI Gateway
> integrado ao ai-service.

Monólito modular responsável por regras de negócio, autenticação,
dispositivos, rotinas, objetivos, auditoria e APIs. Integra-se à IA
**somente** pelo NEXO AI Gateway ([ADR-0003](../docs/decisions/0003-nexo-ai-gateway.md)).

## Executar

Da raiz do repositório (o container `pm-backend` monta esta pasta em `/app`):

```powershell
$dc = 'docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env'
Invoke-Expression "$dc up -d --build"              # postgres + backend
docker exec pm-backend composer install            # após clonar
pwsh infrastructure/scripts/artisan-owner.ps1 migrate   # como dono das tabelas
pwsh infrastructure/scripts/artisan-owner.ps1 test      # suíte completa (banco *_test)
docker exec pm-backend ./vendor/bin/pint           # padrão de código
```

API em `http://127.0.0.1:8000` (somente loopback). Healthcheck: `GET /up`.

As credenciais do banco vêm de `infrastructure/docker/.env` (fonte única),
injetadas pelo compose. O `backend/.env` só precisa de `APP_KEY` e
configurações da aplicação.

## Estrutura

```text
app/
  Models/User.php            Usuário (convenção do framework; auth/Sanctum)
  Modules/
    Shared/                  BelongsToUser, OwnedResourcePolicy, middleware actor:*
    Audit/                   AuditLog + AuditLogger
    Identity/                Registro, login, logout, /me (sessão SPA)
    Devices/                 Dispositivos, token por dispositivo, revogação, consentimento
    Routine/                 Blocos recorrentes da rotina planejada
    Goals/                   Objetivos pessoais, profissionais e de aprendizagem
    Nexo/                    NexoGateway (interface) + HttpNexoGateway, erros tipados, endpoint de conversa
```

Regras: cada módulo só acessa suas tabelas; toda tabela pessoal tem
`user_id`; acesso a registro alheio responde **404**.

## API (v0)

| Método | Rota | Autenticação | Descrição |
|---|---|---|---|
| POST | `/api/auth/register` | — (sessão SPA, throttle 5/min) | Cria conta e inicia sessão |
| POST | `/api/auth/login` | — (sessão SPA, throttle 5/min) | Inicia sessão |
| POST | `/api/auth/logout` | usuário | Encerra sessão |
| GET | `/api/me` | usuário | Perfil |
| DELETE | `/api/me` | usuário (+ senha) | Exclui a conta ([política](../docs/security/retencao-de-dados.md)) |
| GET/POST | `/api/devices` | usuário | Lista / vincula (token retornado **uma vez**, com validade) |
| GET/PATCH/DELETE | `/api/devices/{id}` | usuário | Ver / renomear ou consentimento / revogar |
| POST | `/api/devices/{id}/token` | usuário | Reemite o token (invalida o anterior) |
| GET | `/api/nexo/status` | usuário | IA disponível? (cache de 15 s) |
| POST | `/api/nexo/messages` | usuário (10/min) | Conversa com o NEXO; 503 + `reason` se a IA estiver fora |
| GET/POST | `/api/routine-blocks` | usuário | Lista / cria blocos |
| GET/PATCH/DELETE | `/api/routine-blocks/{id}` | usuário | — |
| GET/POST | `/api/goals` | usuário | Lista / cria objetivos |
| GET/PATCH/DELETE | `/api/goals/{id}` | usuário | — |
| GET | `/api/device/me` | token de dispositivo | Heartbeat, consentimento e `token_renewal_due` |
| POST | `/api/device/token/rotate` | token de dispositivo | Renova o próprio token |

Fluxo SPA: `GET /sanctum/csrf-cookie` → enviar `X-XSRF-TOKEN` em cada
requisição de escrita, com `Origin` listada em `SANCTUM_STATEFUL_DOMAINS`.

## Testes

81 testes cobrindo: autenticação e rate limiting, isolamento entre usuários,
separação de credenciais usuário × dispositivo, ciclo de vida de tokens, exclusão de
conta e retenção, NEXO AI Gateway (com `Http::fake`, sem IA real), degradação com a IA
fora do ar e o banco de testes isolado. `Http::preventStrayRequests()` impede chamadas
HTTP reais acidentais.
