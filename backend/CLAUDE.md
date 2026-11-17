# CLAUDE.md — backend

Complementa o [CLAUDE.md da raiz](../CLAUDE.md). Leia-o primeiro.

## Ambiente
- PHP e Composer existem **somente** no container `pm-backend`.
  Não instale PHP no Windows. Rode tudo com `docker exec pm-backend …`.
- Banco: container `pm-postgres`. Testes usam `procrastinar_menos_test`
  (forçado em `phpunit.xml` via `<env>` **e** `<server>` — não remova).
- O servidor é `php -S` com o roteador do Laravel, não `artisan serve`
  (que descarta as `DB_*` do ambiente).

## Convenções
- Código novo de domínio vai em `app/Modules/<Modulo>/` (Models, Enums,
  Policies, Http/{Controllers,Requests,Resources}).
- Models pessoais: `HasUuids` + `BelongsToUser`; policy estendendo
  `OwnedResourcePolicy`; registrar com `#[UsePolicy]`; listar com `ownedBy()`.
- Controllers autorizam com `Gate::authorize()`; respostas via API Resources.
- Rotas de usuário: `auth:sanctum` + `actor:user`. Rotas de agente:
  `auth:sanctum` + `actor:device` + `abilities:…`.
- Novo parâmetro de rota UUID → adicionar em `Route::patterns` (AppServiceProvider).
- Operações sensíveis (credenciais, consentimento, exportação/exclusão) →
  `AuditLogger::record()`, sem dados pessoais no `metadata`. Classifique o novo evento
  em `config/retention.php`.
- IA só via `NexoGateway`; trate `AiUnavailableException` e degrade sem quebrar o fluxo.
  Em testes, use `Tests\Fakes\FakeNexoGateway` ou `Http::fake()` (requisições reais
  são bloqueadas por `Http::preventStrayRequests()`).
- Encerrar sessão: use `AuthController::endSession()`. Só `logout()` não basta, porque o guard
  do Sanctum mantém o usuário e o driver de sessão em banco gravaria o `user_id`.
- Testes com cookie de sessão em requisições JSON exigem `withCredentials()`.

## Antes de commitar
```powershell
docker exec pm-backend ./vendor/bin/pint --test
pwsh ../infrastructure/scripts/artisan-owner.ps1 test
```

## Banco e RLS (ADR-0008, D8)
- A aplicação usa o papel `pm_runtime`, sujeito à RLS. Migrations e testes passam por
  `infrastructure/scripts/artisan-owner.ps1` (dono das tabelas só nesse processo).
- Tabela nova com dado pessoal do NEXO: `RowLevelSecurity::enable('tabela')` na migration
  e inclusão em `RowLevelSecurityTest::PROTECTED_TABLES`.
- Rotas de usuário passam por `db.user`; jobs e comandos usam `DatabaseUserScope::run($user, …)`,
  um usuário por vez. Sem escopo, as tabelas protegidas aparecem vazias.
- Testes usam `Tests\Concerns\RefreshDatabase` e `asDatabaseUser()` para consultas diretas.
