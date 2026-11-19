# ROADMAP

> Atualize este arquivo ao fim de cada etapa. A seção **"Onde paramos"**
> deve permitir que a próxima sessão continue exatamente daqui.

## Onde paramos (2026-11-19)

- **FASE 4 integrada na `main`** (2026-11-19, após aprovação do proprietário): as três branches
  empilhadas entraram por fast-forward e a investigação, por merge. Suíte completa repetida na
  `main` e versão `20261119-072850-94990d0` publicada em `:8000`.
  - **Aguardando:** testes manuais e avaliação do proprietário. Nenhuma funcionalidade nova antes disso.
  - **`APP_DEBUG`:** o `.env` local continua com `true`. Proposta, a autorizar: `APP_DEBUG=false`
    com log diário (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`). Com `false`, o navegador recebe só
    "Server Error" e o log local mantém mensagem e pilha (verificado).
  - **ETAPA 1, dashboard em produção local:** em `feat/fase4-dashboard-producao`
    ([ADR-0010](docs/decisions/0010-dashboard-na-mesma-origem.md),
    [guia](docs/operations/dashboard-publicacao.md)).
    - O Laravel serve o dashboard em `:8000`, na mesma origem da API.
    - Versões imutáveis com ponteiro (`publish-dashboard.ps1`: publicar, listar, reverter,
      retirar do ar).
    - Cache imutável para os arquivos com hash, `index.html` sem cache e com CSP, interface sem
      sessão.
    - Testes: backend 213; Chromium 19 contra o Laravel + 12 via Vite.
  - **ETAPA 2, perfil e fuso:** em `feat/fase4-perfil` (sobre a ETAPA 1). Sem migration.
    - Tela `/perfil`: nome de exibição, fuso, e-mail e senha.
    - Fuso calculado no backend (deslocamento, horário de verão, próxima mudança) e aplicado ao
      NEXO na mensagem seguinte; o nome continua fora da IA.
    - Trocar a senha encerra as outras sessões.
    - Datas no fuso da conta; prazos sem hora não voltam mais um dia.
  - **ETAPA 3, central "Meus dados":** em `feat/fase4-meus-dados` (sobre a ETAPA 2). Sem
    migration. `/meus-dados` substitui `/privacidade` (o endereço antigo redireciona).
    - Conta e inventário com contagens do backend (`/api/me/data-summary`, sem conteúdo).
    - Autorizações, retenção, exportação e histórico de decisões reaproveitados.
    - Exclusões com prévia de impacto: histórico, memórias e conta. Uma só implementação, usada
      também no Histórico e nas Memórias.
    - A exportação passa a incluir perfil, rotina, objetivos e dispositivos, sem credenciais.
  - **ETAPA 4, sessão anônima:** investigada ([relatório](docs/diagnostics/2026-11-18-sessao-anonima-0734.md)). Origem: um
    `GET /` do Firefox 160 desta máquina; o motivo da navegação não é determinável sem o
    histórico do navegador.
  - **Testes ao fim da FASE 4:**
    - backend 228;
    - telas 53;
    - contrato 11;
    - Chromium: 21 contra o Laravel + 12 via Vite;
    - lint, tipos e build limpos.

- FASE 3: **memória do NEXO integrada na `main`** após revisão final (migrations, RLS,
  permissões do `pm_runtime`, exclusões, segredos, conflitos).
  - Backend: 203 testes. ai-service: 63 testes + 4 de integração com o Ollama real.
  - Backup: 24 verificações de cifragem, integridade e restauração com reaplicação de exclusões.
  - Ponta a ponta com IA e banco reais: 44/44.
- **Decisões do proprietário (2026-11-18):**
  - **BitLocker:** NÃO habilitar até haver dispositivo externo e a chave de recuperação
    guardada fora do computador. Não alterar a criptografia do disco sem nova autorização.
  - **Backup externo:** arquitetura aprovada, mas **sem** tarefas agendadas, sem execução e sem
    destino improvisado até o dispositivo ser definido. Requisitos e operação documentados em
    [backup-e-restauracao.md](docs/security/backup-e-restauracao.md).
  - **Registros do teste ponta a ponta:** mantidos (fictícios e anonimizados), com os prazos
    normais ([diagnóstico](docs/diagnostics/2026-11-18-registros-do-teste-ponta-a-ponta.md)).
- **Dashboard React integrado na `main`** depois dos testes com navegador real e da revisão de
  segurança ([ADR-0009](docs/decisions/0009-dashboard-react.md)).
  - **Funcionalidades:**
    - cadastro e entrada;
    - onboarding com autorizações independentes (D1);
    - painel;
    - conversa (privada, com memórias usadas, IA fora do ar);
    - histórico;
    - memórias e sugestões;
    - privacidade (revogação D7, retenção com prévia, exportação, exclusão de conta);
    - rotina e objetivos.

    Tudo ligado à API real.
  - **Testes:**
    - 40 testes de tela (Vitest 4 + MSW, sem rede);
    - 9 testes de contrato contra o backend **real** e a IA real;
    - 12 testes ponta a ponta no **Chromium** (Playwright): fluxo completo no computador + layout de
      celular, com IA real;
    - contrato e ponta a ponta rodam no banco isolado `procrastinar_menos_e2e`, recriado sob
      demanda por `e2e-db.ps1` e removido com `-Down`;
    - TypeScript estrito, lint e build limpos.
  - **Falha encontrada só no navegador real e corrigida:** ao salvar o onboarding, o painel via o
    estado antigo das autorizações e devolvia a pessoa ao onboarding, preso em "Salvando…".
  - **Revisão de segurança:**
    - sem segredos no código, no histórico nem no build;
    - sem HTML injetado ou `eval`;
    - todas as requisições na mesma origem;
    - sessão só em cookie HttpOnly;
    - Vitest 3 → 4 para corrigir avisos críticos do `tinypool` e do `@vitest/mocker`
      (ferramentas de teste, fora do build).
- **Próxima ação:**
  1. Revisão do proprietário das branches da FASE 4, nesta ordem (empilhadas):
     `feat/fase4-dashboard-producao` → `feat/fase4-perfil` → `feat/fase4-meus-dados`; e
     `docs/investigacao-sessao-anonima`.
  2. Depois: onboarding em etapas, gestão de dispositivos (com os agentes da FASE 5) e E2E no CI
     quando houver CI.

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

## FASE 3 — Fundação do sistema ✅
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
- [x] Dashboard React (Vite + TS + Tailwind + componentes shadcn/Radix) com identidade visual do NEXO, ligado à API real ([ADR-0009](docs/decisions/0009-dashboard-react.md))
- [x] Testes do backend (PHPUnit — 203 testes) e do ai-service (pytest — 63 + 4 de integração)
- [x] Testes do dashboard: 40 de tela (Vitest + MSW) + 9 de contrato + 12 ponta a ponta no Chromium (Playwright), com backend e IA reais em banco isolado

## FASE 4 — Primeira versão funcional
- [x] Dashboard servido pelo Laravel na mesma origem, com versões e reversão (ADR-0010)
- [ ] Cadastro/login, onboarding em etapas
- [x] Perfil e fuso (nome, fuso IANA com horário de verão, e-mail, senha)
- [ ] Dashboard inicial, rotina, objetivos, dispositivos
- [ ] Conversa básica com o NEXO usando contexto autorizado
- [x] Central "Meus dados" (inventário, autorizações, retenção, exportação, exclusões com prévia)

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
- ~~Exportação só do NEXO~~ → inclui perfil, rotina, objetivos e dispositivos (FASE 4, etapa 3).
- Primeira mensagem após o modelo ficar ocioso leva ~25 s (carga na GPU); avaliar `keep_alive` maior ou pré-aquecimento.
- `fastapi.testclient` emite aviso de depreciação (Starlette recomenda `httpx2`); acompanhar na próxima atualização de dependências.
- Backup não agendado e BitLocker desligado no `C:`: ambos aguardam o dispositivo externo e nova autorização do proprietário.
- RLS cobre as tabelas do NEXO; dispositivos, rotinas, objetivos e auditoria seguem isolados só pela aplicação (policies). Avaliar estender a RLS na FASE 4.
- Sugestões reais do `qwen3:8b` têm qualidade irregular (erro de grafia, sugestão derivada da fala do NEXO); a confirmação da pessoa é a barreira. Avaliar ajuste de prompt com um conjunto de avaliação.
- Limiar e quantidade da recuperação (0,35 / 6) não calibrados: falta o conjunto de avaliação (~50 pares).
- Detecção de dados sensíveis é por padrões e palavras-chave: não é infalível (documentado na política).
- Testes com navegador usam só o Chromium do Playwright sem janela (~290 MB em `%LOCALAPPDATA%\ms-playwright`, fora do repositório; Chrome e Edge não instalados).
- `picomatch` (dependência do Vite, só em desenvolvimento) tem aviso "alto" de ReDoS sem correção publicada. Os padrões são do próprio projeto, então não é explorável aqui. Acompanhar.
- O `pm-backend-e2e` monta o mesmo `backend/` e grava no mesmo `storage/logs` do backend de desenvolvimento (só dados fictícios). Separar se os testes crescerem.
- Dashboard ainda não tem modo de produção na mesma origem da API (hoje: dev server com proxy).
