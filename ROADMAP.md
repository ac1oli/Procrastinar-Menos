# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-18)

- FASE 3: **memória do NEXO implementada e validada** ([ADR-0008](docs/decisions/0008-memoria-do-nexo.md),
  diferenças do plano em §8), na branch `feat/nexo-memoria`, aguardando revisão do proprietário
  antes de integrar na `main`.
  - Backend: 203 testes (882 verificações), sem IA real. ai-service: 63 testes + 4 de integração
    com o Ollama real. Backup: 17 verificações de cifragem e restauração. Ponta a ponta com IA e
    banco reais: 44/44.
  - A aplicação agora conecta como `pm_runtime` (sem bypass de RLS); `pm_app` só via
    `artisan-owner.ps1`.
- **Aguardando o proprietário:**
  1. Autorizar (ou não) habilitar o BitLocker no `C:` (desligado; TPM pronto).
  2. Dispositivo físico separado para o backup e autorização para agendar as tarefas no Windows
     ([backup-e-restauracao.md](docs/security/backup-e-restauracao.md) §4).
  3. Autorizar remover do banco principal os registros anonimizados do teste ponta a ponta
     (auditoria sem identificadores e 11 entradas do diário com UUIDs fictícios). Expiram
     sozinhos em 365 e 35 dias.
  4. Revisar e aprovar a integração de `feat/nexo-memoria` na `main`.
- **Próxima ação após a integração:** dashboard React (FASE 3, último item), começando pelas
  telas de consentimento (primeiro acesso), memórias e histórico.

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
- [x] Arquitetura da memória do NEXO aprovada ([ADR-0008](docs/decisions/0008-memoria-do-nexo.md))
- [x] Memória do NEXO: consentimentos independentes, histórico mascarado com retenção, memórias com embeddings `vector(1024)` assíncronos, recuperação híbrida, sugestões com confirmação, exportação, diário de exclusões
- [x] RLS forçada nas tabelas do NEXO com papel de execução sem bypass (`pm_runtime`)
- [x] Container `pm-queue` (fila)
- [x] Backup cifrado + restauração com reaplicação de exclusões (scripts e teste); agendamento pendente de dispositivo
- [ ] Dashboard React (Vite + TS + Tailwind + shadcn/ui) com sistema visual base
- [x] Testes do backend (PHPUnit — 203 testes) e do ai-service (pytest — 63 + 4 de integração)
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
- Exportação: dados do NEXO prontos (`/api/me/exports`); perfil, rotina, objetivos e dispositivos na FASE 4.
- Primeira mensagem após o modelo ficar ocioso leva ~25 s (carga na GPU); avaliar `keep_alive` maior ou pré-aquecimento.
- `fastapi.testclient` emite aviso de depreciação (Starlette recomenda `httpx2`); acompanhar na próxima atualização de dependências.
- Backup ainda não agendado: o servidor tem um único disco (falta dispositivo separado). BitLocker desligado no `C:`.
- Sugestões reais do `qwen3:8b` têm qualidade irregular (erro de grafia, sugestão derivada da fala do NEXO); a confirmação da pessoa é a barreira. Avaliar ajuste de prompt com um conjunto de avaliação.
- Limiar e quantidade da recuperação (0,35 / 6) não calibrados: falta o conjunto de avaliação (~50 pares).
- Detecção de dados sensíveis é por padrões e palavras-chave: não é infalível (documentado na política).
