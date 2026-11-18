# CLAUDE.md — instruções permanentes

Projeto **Procrastinar Menos** com o assistente **NEXO**: sistema inteligente
de foco e desenvolvimento pessoal. Repositório oficial:
https://github.com/ac1oli/Procrastinar-Menos (branch principal `main`).

Leia antes de trabalhar: [ARCHITECTURE.md](ARCHITECTURE.md),
[ROADMAP.md](ROADMAP.md) (onde paramos), [SECURITY.md](SECURITY.md),
[DEVELOPMENT.md](DEVELOPMENT.md) e os ADRs em `docs/decisions/`.

## Stack
Laravel 13 (PHP 8.3+, em container) · PostgreSQL 18 + pgvector · FastAPI
(Python) · Ollama nativo (GPU AMD via Vulkan) · React + TypeScript +
Tailwind + shadcn/ui · C#/.NET (agente Windows) · Kotlin + Compose
(Android) · TypeScript MV3 (extensão) · Docker Desktop + WSL2.

## Regras de arquitetura
- Monólito modular no backend + **um** serviço de IA. Sem microsserviços novos sem ADR.
- O backend só fala com IA via interface `NexoGateway`; o ai-service só fala
  com modelos via `ModelProvider`. Nunca acoplar regra de negócio ao Ollama.
- Memória do NEXO vive no PostgreSQL (não no modelo, não no ai-service).
- Recursos básicos (rotina, foco, bloqueios) funcionam sem IA e, nos agentes, sem servidor.
- Toda tabela de dado pessoal tem `user_id`; autorização sempre no backend.
- Distinguir níveis de evidência: observada · declarada · consultada · praticada · demonstrada.
- Decisão estrutural relevante → novo ADR em `docs/decisions/` (use o template 0000).

## Regras de trabalho
- Não implementar funcionalidades fictícias nem telas estáticas como "prontas".
  Dados de demonstração devem ser identificados como tal.
- Funcionalidade só é "concluída" com testes executados e passando.
- Não inventar resultados de testes ou de validação de modelos.
- Atualizar `ROADMAP.md` ao fim de cada etapa (estado + próximo passo).
- Não alterar arquivos fora deste repositório nem configurações globais sem necessidade.

## Git
- Commits pequenos, Conventional Commits em português.
- Branches `feat/`, `fix/`, `docs/`, `chore/` a partir de `main`.
- Antes de commit/push: rodar testes, revisar diff, procurar segredos.
- Proibido sem autorização explícita: `git reset --hard`, `git clean -fd`,
  force push, apagar branches importantes, reescrever histórico remoto.

## Segredos
- Nunca versionar `.env`, chaves, tokens, dumps, logs com dados pessoais.
- Usar `.env.example` com valores fictícios. Portas de serviços só em `127.0.0.1`.

## Comandos úteis
```powershell
pwsh infrastructure/scripts/check-env.ps1 [-TestModel qwen3:8b]
docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env up -d --build
pwsh infrastructure/scripts/provision-db-roles.ps1      # papel pm_runtime (1x por cluster)
pwsh infrastructure/scripts/artisan-owner.ps1 migrate    # migrations (dono das tabelas)
pwsh infrastructure/scripts/artisan-owner.ps1 test       # backend (sem IA real; testes como pm_runtime)
docker exec pm-ai-service python -m pytest -q           # ai-service (mocks)
docker exec -e RUN_OLLAMA_TESTS=1 pm-ai-service python -m pytest -q -m integration
ollama list
cd dashboard; pnpm test; pnpm typecheck; pnpm lint; pnpm build   # dashboard (sem rede)
pwsh infrastructure/scripts/e2e-db.ps1; cd dashboard; pnpm test:contract   # contrato com backend real isolado
```

## Armadilhas conhecidas
- Git no Windows é case-insensitive: nunca ignorar `models/` genérico
  (quebraria `backend/app/Models`).
- RX 5700 XT não é suportada pelo ROCm; o Ollama usa Vulkan. O aviso
  "AMD driver is too old" no log é do ROCm e pode ser ignorado.
- Docker Desktop não repassa GPU AMD para containers — Ollama fica nativo.
- Porta 5432 pertence a um PostgreSQL 16 nativo de **outro projeto** — não
  tocar. O banco deste projeto é o container `pm-postgres` em `127.0.0.1:5433`.
- A imagem `composer:2` não tem `pdo_pgsql`/`intl`/`bcmath`; o backend usa Dockerfile próprio.
- Containers alcançam o Ollama (loopback do Windows) por `host.docker.internal:11434`.
- Mudou o contrato backend ↔ ai-service? Atualize os dois lados, os testes e o ADR-0007.
- Novo tipo de evento de auditoria → classificar em `config/retention.php` (reter ou não).
- Saída de modelo nunca vai ao usuário sem passar pela remoção de `<think>`.
- A aplicação usa `pm_runtime` (sujeito à RLS). Migrations e testes: `artisan-owner.ps1`.
  Tabela nova com dado do NEXO → `RowLevelSecurity::enable()` + `RowLevelSecurityTest`.
- `migrate:fresh` não apaga funções do PostgreSQL: use `CREATE OR REPLACE FUNCTION`.
- O uvicorn do ai-service não recarrega código: `docker restart pm-ai-service` após mudanças.
- Em testes, trocar de usuário com sessão web exige `flushSession()` (AuthenticateSession).
- No PowerShell, `H` é alias de `Get-History`: não nomeie funções de script com aliases.
- Conteúdo de conversa/memória nunca vai para fila, log ou auditoria: jobs levam só IDs.
- Dashboard: nada de tokens/credenciais no navegador; todo acesso à API por `src/api/endpoints.ts`.
- Não usar o CLI do shadcn: ele instalou um pacote `cn` alheio (ADR-0009). Componentes em `src/components/ui`.
- Teste de contrato do dashboard usa `procrastinar_menos_e2e` (`pm-backend-e2e`, :8010); nunca o banco principal.
