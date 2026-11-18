# Desenvolvimento

Guia para preparar e executar o ambiente local. Todos os comandos partem
da raiz do repositório, em PowerShell (Windows).

## 1. Pré-requisitos

| Ferramenta | Versão validada | Uso | Instalação |
|---|---|---|---|
| Git | 2.55 | Versionamento | já presente |
| WSL2 | pacote `Microsoft.WSL` | Backend do Docker | `winget install Microsoft.WSL` (+ reiniciar) |
| Docker Desktop | build 245822 | PostgreSQL/pgvector, PHP/Laravel | `winget install Docker.DockerDesktop` |
| Ollama | 0.40.16 | Modelos locais (GPU via Vulkan) | `winget install Ollama.Ollama` |
| Node.js / pnpm | 24.22 / 12.15 | Dashboard, extensão | já presentes |
| Python | 3.14.7 | ai-service | já presente |
| .NET SDK | — | Agente Windows (FASE 5) | ainda não instalado |
| Android Studio / JDK 17+ | — | App Android (FASE 5) | ainda não instalado |

PHP e Composer **não** são instalados no Windows: rodarão em container
(ver [ADR-0002](docs/decisions/0002-ambiente-docker-e-ollama-nativo.md)).

> **Python 3.14:** validado na FASE 2 com fastapi, pydantic, uvicorn, httpx,
> psycopg e pgvector. O ai-service usará um ambiente virtual próprio (`ai-service/.venv`).

## 2. Verificar o ambiente

```powershell
pwsh infrastructure/scripts/check-env.ps1
pwsh infrastructure/scripts/check-env.ps1 -TestModel qwen3:8b   # inclui teste real de geração
```

## 3. Banco de dados (PostgreSQL 18 + pgvector)

```powershell
Copy-Item infrastructure/docker/.env.example infrastructure/docker/.env
# edite infrastructure/docker/.env e defina uma senha forte
docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env up -d
docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env ps
```

O banco fica em `127.0.0.1:5433`. A porta 5432 **não** é usada porque,
nesta máquina, pertence a um PostgreSQL 16 nativo de outro projeto.
Conexão manual: `docker exec -it pm-postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'`.

As extensões `vector`, `pgcrypto` e `citext` são criadas na primeira
inicialização do volume ([init/01-extensions.sql](infrastructure/database/init/01-extensions.sql)).

## 4. Ollama

O instalador registra o Ollama para iniciar com o Windows, escutando em
`http://127.0.0.1:11434`.

```powershell
ollama list
ollama pull qwen3:8b               # chat principal
ollama pull qwen3-embedding:0.6b   # embeddings
```

GPU: a RX 5700 XT (gfx1010) é usada pelo backend **Vulkan**. Confirme no
log (`%LOCALAPPDATA%\Ollama\server.log`) a linha `inference compute ... library=Vulkan`.
O log também avisa "AMD driver is too old" — isso se refere ao backend
ROCm, que não suporta esta GPU de qualquer forma; não afeta o Vulkan.

## 5. Backend (Laravel)

```powershell
docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env up -d --build
docker exec pm-backend composer install
Copy-Item backend/.env.example backend/.env   # se ainda não existir
docker exec pm-backend php artisan key:generate
pwsh infrastructure/scripts/provision-db-roles.ps1     # cria o papel de execução pm_runtime
pwsh infrastructure/scripts/artisan-owner.ps1 migrate   # migrations como dono das tabelas
pwsh infrastructure/scripts/artisan-owner.ps1 test      # suíte completa
```

A aplicação conecta com `pm_runtime` (sem superusuário, sem BYPASSRLS, sem ser dona das
tabelas), para que a Row-Level Security das tabelas do NEXO valha (ADR-0008, D8). O dono
(`pm_app`) só é usado por migrations, pela recriação do banco de testes e por backups; as
credenciais dele entram apenas no processo iniciado por `artisan-owner.ps1`.

API em `http://127.0.0.1:8000`. Detalhes, rotas e convenções em
[backend/README.md](backend/README.md).

## 6. ai-service (FastAPI)

Sobe junto com o compose (`pm-ai-service`, `127.0.0.1:8100`). Requer
`AI_SERVICE_TOKEN` em `infrastructure/docker/.env` (veja o `.env.example`).

```powershell
docker exec pm-ai-service python -m pytest -q
docker exec -e RUN_OLLAMA_TESTS=1 pm-ai-service python -m pytest -q -m integration
docker exec pm-ai-service ruff check .
```

Detalhes em [ai-service/README.md](ai-service/README.md).

## 7. Dashboard (React)

```powershell
cd dashboard
pnpm install
pnpm dev     # http://localhost:5173, com proxy de /api e /sanctum para 127.0.0.1:8000
pnpm test    # testes de tela com backend falso (sem rede)
```

O teste de contrato roda contra um backend real **isolado**, que nunca usa o banco principal:

```powershell
pwsh infrastructure/scripts/e2e-db.ps1   # cria procrastinar_menos_e2e e sobe pm-backend-e2e (:8010)
cd dashboard; pnpm test:contract
```

Detalhes em [dashboard/README.md](dashboard/README.md).

## 8. Demais componentes

Cada pasta terá seu próprio guia ao ser inicializada: `desktop-agent/`, `mobile/`,
`browser-extension/`.

## 9. Fluxo Git

- `main` é estável. Trabalho em branches `feat/<modulo>-<descricao>`,
  `fix/...`, `docs/...`, `chore/...`.
- Commits pequenos, no padrão Conventional Commits em português
  (`feat(backend): cadastro de dispositivos`).
- Antes de cada push: testes do componente, revisão do diff, busca por segredos.
- Proibido sem autorização: `git reset --hard`, `git clean -fd`, force push,
  apagar branches remotas.
