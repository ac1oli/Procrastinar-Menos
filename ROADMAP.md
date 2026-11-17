# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-17)

- FASE 3: **ai-service e NEXO AI Gateway validados** com a IA real e com a IA fora do ar.
  Backend: 81 testes; ai-service: 43 testes + 2 de integração com o Ollama real;
  smoke test HTTP ponta a ponta: 15/15.
- Definições do proprietário atendidas: exclusão de conta com retenção mínima e anônima
  ([ADR-0006](docs/decisions/0006-retencao-e-exclusao-de-conta.md)), senha do PostgreSQL
  rotacionada (48 caracteres, fora do Git) e ciclo de vida dos tokens de dispositivo
  ([ADR-0005](docs/decisions/0005-autenticacao-e-dispositivos.md)).
- Memória do NEXO: **arquitetura proposta, implementação aguardando aprovação.**
  [ADR-0008](docs/decisions/0008-memoria-do-nexo.md) (status: proposta),
  [política de memória](docs/security/politica-de-memoria.md) e
  [proposta de arquitetura](docs/architecture/memoria-do-nexo.md) na branch
  `docs/adr-memoria-nexo`. Nenhuma migration, tabela ou endpoint de memória foi criado.
- **Bloqueio:** o proprietário precisa decidir D1–D10 (seção 6 do ADR-0008).
- **Próxima ação após a aprovação** — branch `feat/nexo-memoria`, na ordem do ADR-0008 §5:
  1. Consentimentos independentes (`consents` + histórico) e verificação no gateway.
  2. Histórico de conversas + retenção.
  3. Memórias + embeddings `vector(1024)` assíncronos + revisão.
  4. Recuperação híbrida (pgvector + busca textual) e contrato novo com o ai-service (ADR-0007).
  5. Sugestões de memória com confirmação.
  6. Exclusão de conta estendida, exportação, diário de exclusões e backup (D9).
  7. Depois: dashboard React (FASE 3, último item).

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
- [x] ai-service FastAPI em container: `ModelProvider` + `OllamaProvider`, persona versionada, remoção de `<think>`, auth serviço-a-serviço, limite de concorrência ([ADR-0007](docs/decisions/0007-servico-de-ia-contrato-e-resiliencia.md))
- [x] `NexoGateway` no backend: erros tipados (unavailable/timeout/busy/invalid_response/misconfigured), 503 + Retry-After, rate limit de 10/min, núcleo funcionando sem IA
- [x] Exclusão de conta + política de retenção da auditoria ([retencao-de-dados.md](docs/security/retencao-de-dados.md))
- [x] Tokens de dispositivo: expiração (90 dias), rotação pelo agente, reemissão pelo usuário, limpeza diária
- [x] Container `pm-scheduler` para as tarefas agendadas
- [x] Arquitetura da memória do NEXO proposta ([ADR-0008](docs/decisions/0008-memoria-do-nexo.md), aguardando aprovação)
- [ ] Memória do NEXO (consentimentos, histórico, memórias + embeddings `vector(1024)`) — **aguarda aprovação do ADR-0008**
- [ ] Dashboard React (Vite + TS + Tailwind + shadcn/ui) com sistema visual base
- [x] Testes do backend (PHPUnit — 81 testes) e do ai-service (pytest — 43 + 2 de integração)
- [ ] Testes do dashboard (Vitest)

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
- ~~Senha do banco curta~~ → rotacionada em 2026-11-17.
- ~~Retenção de `audit_logs`~~ → ADR-0006. Prazos de 90/365 dias a revisar juridicamente na FASE 6.
- Pareamento de dispositivos por código curto: FASE 5.
- Exportação de dados (portabilidade) ainda não implementada (FASE 4, painel "Meus dados").
- Primeira mensagem após o modelo ficar ocioso leva ~25 s (carga na GPU); avaliar `keep_alive` maior ou pré-aquecimento.
- `fastapi.testclient` emite aviso de depreciação (Starlette recomenda `httpx2`); acompanhar na próxima atualização de dependências.
- Conversas com o NEXO ainda não são armazenadas (sem memória) — intencional até a aprovação do ADR-0008.
- Não existe backup do banco. Proposta no ADR-0008 (D9); o estado do BitLocker no servidor não foi verificado.
