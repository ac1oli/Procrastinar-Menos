# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-16)

- FASES 1 e 2 concluídas. FASE 3 em andamento: **fundação do backend pronta**
  (38 testes passando + smoke test HTTP 9/9).
- **Próxima ação — FASE 3, ai-service + NEXO AI Gateway** (branch `feat/ai-service-fundacao`):
  1. `ai-service/` FastAPI com `ModelProvider` + `OllamaProvider` (config de modelos, remoção de `<think>`).
  2. Endpoints `/health`, `/v1/chat`, `/v1/embed`; testes com pytest (provider falso + teste real opcional).
  3. No backend: interface `NexoGateway`, implementação HTTP e erro tipado `AiUnavailable`.
  4. Depois: tabelas de memória do NEXO com `vector(1024)` e dashboard React.

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

## FASE 3 — Fundação do sistema 🟡
- [x] Backend Laravel 13.42 (PHP 8.4 em container) com PostgreSQL, módulos em `app/Modules`
- [x] Usuários (UUID), autenticação SPA por sessão + CSRF, rate limiting, policies por `user_id` (404 para recurso alheio)
- [x] Dispositivos: vínculo com token próprio e escopos, heartbeat, revogação, consentimento auditado ([ADR-0005](docs/decisions/0005-autenticacao-e-dispositivos.md))
- [x] Rotinas (blocos recorrentes, inclusive atravessando a meia-noite) e objetivos — CRUD
- [x] Auditoria de operações sensíveis (`audit_logs`)
- [x] Banco de testes isolado (`procrastinar_menos_test`) com verificação automática
- [ ] ai-service FastAPI com `ModelProvider` + `OllamaProvider` (+ remoção de `<think>`)
- [ ] `NexoGateway` no backend + tratamento de indisponibilidade da IA
- [ ] Tabelas de memória do NEXO + embeddings `vector(1024)`
- [ ] Dashboard React (Vite + TS + Tailwind + shadcn/ui) com sistema visual base
- [x] Testes fundamentais do backend (PHPUnit — 38 testes)
- [ ] Testes do ai-service (pytest) e do dashboard (Vitest)

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
- LGPD: definir retenção de `audit_logs` após exclusão de conta (hoje `user_id` vira NULL e o IP permanece).
- Tokens de dispositivo não expiram; pareamento por código curto e rotação de tokens na FASE 5.
- Fluxo de exclusão/exportação de conta ainda não implementado (FASE 4, painel "Meus dados").
