# backend — Laravel 13 (PHP 8.4, em container)

> **Status:** fundação implementada e testada (FASE 3). Ainda sem
> integração com IA (o `NexoGateway` é o próximo passo).

Monólito modular responsável por regras de negócio, autenticação,
dispositivos, rotinas, objetivos, auditoria e APIs. Integra-se à IA
**somente** pelo NEXO AI Gateway ([ADR-0003](../docs/decisions/0003-nexo-ai-gateway.md)).

## Executar

Da raiz do repositório (o container `pm-backend` monta esta pasta em `/app`):

```powershell
$dc = 'docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env'
Invoke-Expression "$dc up -d --build"              # postgres + backend
docker exec pm-backend composer install            # após clonar
docker exec pm-backend php artisan migrate
docker exec pm-backend php artisan test            # suíte completa (banco *_test)
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
| GET/POST | `/api/devices` | usuário | Lista / vincula (token retornado **uma vez**) |
| GET/PATCH/DELETE | `/api/devices/{id}` | usuário | Ver / renomear ou consentimento / revogar |
| GET/POST | `/api/routine-blocks` | usuário | Lista / cria blocos |
| GET/PATCH/DELETE | `/api/routine-blocks/{id}` | usuário | — |
| GET/POST | `/api/goals` | usuário | Lista / cria objetivos |
| GET/PATCH/DELETE | `/api/goals/{id}` | usuário | — |
| GET | `/api/device/me` | token de dispositivo | Heartbeat e estado de consentimento |

Fluxo SPA: `GET /sanctum/csrf-cookie` → enviar `X-XSRF-TOKEN` em cada
requisição de escrita, com `Origin` listada em `SANCTUM_STATEFUL_DOMAINS`.

## Testes

38 testes de feature/unidade cobrindo: autenticação e rate limiting,
isolamento entre usuários, separação de credenciais usuário × dispositivo,
revogação de tokens, auditoria de consentimento, validações e o uso do
banco de testes isolado com pgvector.
