# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-16)

- FASES 1 e 2 concluídas. `check-env.ps1 -TestModel qwen3:8b` passa 100%.
- **Próxima ação — FASE 3, backend** (branch `feat/backend-fundacao`):
  1. Dockerfile do backend: PHP 8.4 com `pdo_pgsql`, `intl`, `bcmath`, `zip`, `pcntl`.
  2. `composer create-project laravel/laravel` (13.x) em `backend/` via container.
  3. Conectar ao `pm-postgres` (`host.docker.internal:5433` ou serviço no mesmo compose).
  4. Estruturar módulos, usuários, Sanctum, policies e os primeiros testes.

## FASE 1 — Diagnóstico e planejamento ✅
- [x] Inspeção de hardware, SO e ferramentas ([diagnóstico](docs/diagnostics/2026-11-16-fase1.md))
- [x] Inspeção do repositório oficial (vazio, branch `main`)
- [x] Arquitetura definida ([ARCHITECTURE.md](ARCHITECTURE.md), ADRs 0001–0004)
- [x] Modelo local escolhido por benchmark real ([ADR-0004](docs/decisions/0004-modelo-local.md))

## FASE 2 — Preparação do ambiente ✅
- [x] Estrutura de diretórios e documentação base
- [x] `.gitignore`, `.gitattributes`, `.editorconfig`
- [x] WSL2 instalado (aguardando reboot)
- [x] Docker Desktop instalado (aguardando reboot)
- [x] Ollama instalado e validado na GPU (Vulkan)
- [x] Modelos `qwen3:8b` e `qwen3-embedding:0.6b` baixados e testados
- [x] `compose.yml` do PostgreSQL 18 + pgvector (somente 127.0.0.1)
- [x] Script `check-env.ps1` de verificação
- [x] Reboot + Docker Desktop operacional (Engine 29.9.3, WSL2)
- [x] PostgreSQL 18.4 + pgvector 0.8.2 validados (HNSW + teste ponta a ponta com embeddings reais)
- [x] Porta movida para 5433 (5432 ocupada por PostgreSQL 16 nativo de outro projeto)
- [x] Limite de memória do WSL2 — não necessário (o Docker Desktop já limita a ~7,7 GiB)
- [x] PHP/Composer em container verificados (PHP 8.5.12, Composer 2.11.2) — faltam extensões, o backend terá Dockerfile próprio
- [x] Python 3.14 validado com as dependências do ai-service

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
- PostgreSQL 16 nativo (outro projeto) escuta em `0.0.0.0:5432` — cabe ao proprietário avaliar.
- Senha do banco local é curta; trocar por uma forte antes de guardar dados reais.
