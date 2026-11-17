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

> **Python 3.14:** algumas bibliotecas de IA podem ainda não ter wheels
> para 3.14. O ai-service usará ambiente virtual próprio; se houver
> incompatibilidade, será usado Python 3.12 via container (decisão na FASE 3).

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

## 5. Componentes

Cada pasta terá seu próprio guia ao ser inicializada:
`backend/`, `ai-service/`, `dashboard/`, `desktop-agent/`, `mobile/`, `browser-extension/`.

## 6. Fluxo Git

- `main` é estável. Trabalho em branches `feat/<modulo>-<descricao>`,
  `fix/...`, `docs/...`, `chore/...`.
- Commits pequenos, no padrão Conventional Commits em português
  (`feat(backend): cadastro de dispositivos`).
- Antes de cada push: testes do componente, revisão do diff, busca por segredos.
- Proibido sem autorização: `git reset --hard`, `git clean -fd`, force push,
  apagar branches remotas.
