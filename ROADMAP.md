# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-16)

- FASE 1 concluída. FASE 2 parcialmente concluída.
- **Bloqueio atual:** o PC precisa ser **reiniciado** para ativar WSL2 e o Docker Desktop.
- **Próxima ação após o reboot:**
  1. Abrir o Docker Desktop uma vez (aceitar termos, backend WSL2).
  2. Criar `.wslconfig` limitando memória do WSL2 (proposta: 6 GB) — confirmar com o proprietário.
  3. `Copy-Item infrastructure/docker/.env.example infrastructure/docker/.env` e definir senha.
  4. Subir PostgreSQL: `docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env up -d`.
  5. `pwsh infrastructure/scripts/check-env.ps1 -TestModel qwen3:8b` → tudo OK exceto .NET.
  6. Iniciar FASE 3 pelo backend Laravel (branch `feat/backend-fundacao`).

## FASE 1 — Diagnóstico e planejamento ✅
- [x] Inspeção de hardware, SO e ferramentas ([diagnóstico](docs/diagnostics/2026-11-16-fase1.md))
- [x] Inspeção do repositório oficial (vazio, branch `main`)
- [x] Arquitetura definida ([ARCHITECTURE.md](ARCHITECTURE.md), ADRs 0001–0004)
- [x] Modelo local escolhido por benchmark real ([ADR-0004](docs/decisions/0004-modelo-local.md))

## FASE 2 — Preparação do ambiente 🟡
- [x] Estrutura de diretórios e documentação base
- [x] `.gitignore`, `.gitattributes`, `.editorconfig`
- [x] WSL2 instalado (aguardando reboot)
- [x] Docker Desktop instalado (aguardando reboot)
- [x] Ollama instalado e validado na GPU (Vulkan)
- [x] Modelos `qwen3:8b` e `qwen3-embedding:0.6b` baixados e testados
- [x] `compose.yml` do PostgreSQL 18 + pgvector (somente 127.0.0.1)
- [x] Script `check-env.ps1` de verificação
- [ ] Reboot + Docker Desktop operacional
- [ ] PostgreSQL + pgvector em execução e validado (`CREATE EXTENSION vector`)
- [ ] Limite de memória do WSL2
- [ ] Imagem/ambiente PHP 8.4 + Composer em container
- [ ] Ambiente Python do ai-service (venv; validar compatibilidade com 3.14)

## FASE 3 — Fundação do sistema
- [ ] Backend Laravel 13 (monólito modular) com PostgreSQL
- [ ] Usuários, autenticação (Sanctum), policies por `user_id`
- [ ] Dispositivos (cadastro, token por dispositivo, revogação)
- [ ] Estrutura inicial de rotinas e objetivos
- [ ] ai-service FastAPI com `ModelProvider` + `OllamaProvider` (+ remoção de `<think>`)
- [ ] `NexoGateway` no backend + tratamento de indisponibilidade da IA
- [ ] Tabelas de memória do NEXO + embeddings `vector(1024)`
- [ ] Dashboard React (Vite + TS + Tailwind + shadcn/ui) com sistema visual base
- [ ] Testes fundamentais (PHPUnit/Pest, pytest, Vitest)

## FASE 4 — Primeira versão funcional
- [ ] Cadastro/login, perfil, onboarding em etapas
- [ ] Dashboard inicial, rotina, objetivos, dispositivos
- [ ] Conversa básica com o NEXO usando contexto autorizado
- [ ] Configurações básicas e painel "Meus dados"

## FASE 5 — Evolução dos módulos
- [ ] Agente Windows (.NET) · [ ] Extensão Chrome/Edge · [ ] App Android
- [ ] Foco e bloqueios · [ ] Central de Conhecimento · [ ] Métodos de aprendizagem
- [ ] Desenvolvimento profissional · [ ] Inteligência contextual · [ ] Notificações

## FASE 6 — Consolidação e preparação comercial
- [ ] Revisão de arquitetura/segurança/LGPD · [ ] Nuvem · [ ] Backups · [ ] Distribuição

## Pendências e riscos
- RAM de 16 GB é o gargalo principal do ambiente local.
- Licença do Docker Desktop deve ser revista antes de uso comercial.
- `qwen3:4b` baixado mas não recomendado — decidir se remove.
