# Arquitetura — Procrastinar Menos + NEXO

> Local first, cloud ready. Decisões detalhadas em [docs/decisions/](docs/decisions/).

## 1. Visão geral

```text
 ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
 │ App Android  │ │ Agente Win   │ │  Extensão    │ │  Dashboard   │
 │ Kotlin/Compose│ │  C# / .NET   │ │ Chrome/Edge  │ │ React + TS   │
 │ fila offline │ │ fila offline │ │              │ │              │
 └──────┬───────┘ └──────┬───────┘ └──────┬───────┘ └──────┬───────┘
        │   HTTPS (LAN) REST + WebSockets, token por dispositivo   │
        └────────────────┬──────────────────┬──────────────────────┘
                         ▼                  │
              ┌──────────────────────────────────────┐
              │ Backend Laravel 13 (monólito modular)│
              │ Identidade · Dispositivos · Rotina   │
              │ Objetivos · Foco · Conhecimento      │
              │ Aprendizagem · Carreira · Privacidade│
              │ NEXO (orquestração + memória)        │
              │        └── NexoGateway (interface)   │
              └──────┬─────────────────────┬─────────┘
                     │ SQL                 │ HTTP interno
                     ▼                     ▼
          ┌────────────────────┐  ┌──────────────────────┐
          │ PostgreSQL 18      │  │ ai-service (FastAPI) │
          │ + pgvector         │  │ persona + prompts    │
          │ (fonte da verdade, │  │ ModelProvider        │
          │  memória do NEXO)  │  │  └── OllamaProvider  │
          └────────────────────┘  └──────────┬───────────┘
                                             │ host.docker.internal
                                             ▼
                                   ┌──────────────────────┐
                                   │ Ollama (nativo, GPU  │
                                   │ AMD via Vulkan,      │
                                   │ só 127.0.0.1)        │
                                   └──────────────────────┘
```

Containers (Docker Compose): `pm-postgres`, `pm-backend`, `pm-scheduler` (tarefas
agendadas), `pm-queue` (fila: embeddings, sugestões, exportações) e `pm-ai-service`. O Ollama
roda nativo no Windows.

Banco: a aplicação conecta como `pm_runtime` (sem superusuário/BYPASSRLS, sem ser dona das
tabelas); as tabelas do NEXO têm Row-Level Security forçada por `user_id`. Memória do NEXO:
[ADR-0008](docs/decisions/0008-memoria-do-nexo.md) e
[arquitetura da memória](docs/architecture/memoria-do-nexo.md).

## 2. Componentes e responsabilidades

| Componente | Responsabilidade | Não faz |
|---|---|---|
| `backend/` | Regras de negócio, autenticação (Sanctum), autorização por usuário (policies), dispositivos, rotinas, foco, conhecimento, memória do NEXO, auditoria, APIs REST e WebSockets (Reverb) | Inferência de IA |
| `ai-service/` | Prompts, RAG, embeddings, classificação de conteúdo, adaptadores de provedores | Guardar estado de usuário; decidir autorização |
| `dashboard/` | Interface web (React + TS), mesma origem da API via proxy, sessão HttpOnly ([ADR-0009](docs/decisions/0009-dashboard-react.md)) | Regras de negócio, autorização, guardar credenciais |
| `desktop-agent/` | Observar apps/processos (com consentimento), aplicar regras de foco localmente, fila offline | Capturar teclado, telas ou conteúdo privado |
| `mobile/` | Uso de apps (UsageStatsManager), restrições permitidas pelo Android, fila offline | Prometer controle absoluto do dispositivo |
| `browser-extension/` | Domínio/URL/título da aba, classificação, bloqueio de sites | Ler formulários, senhas, mensagens |
| PostgreSQL + pgvector | Fonte única da verdade e memória vetorial | — |
| Ollama | Execução dos modelos locais | Ser exposto à rede |

## 3. Princípios de projeto

1. **IA é opcional para o núcleo.** Rotinas, regras de foco e bloqueios
   funcionam sem o ai-service. Agentes aplicam regras já sincronizadas
   mesmo com o servidor desligado.
2. **Memória fora do modelo.** Perfil, preferências, decisões,
   recomendações e feedback ficam no PostgreSQL; o modelo é substituível.
3. **Níveis de evidência explícitos.** Todo dado de atividade carrega sua
   origem e confiança: `observada` (agente) · `declarada` (usuário) ·
   `consultada` (conteúdo aberto) · `praticada` (exercício feito) ·
   `demonstrada` (avaliação correta). O NEXO nunca promove um nível sem evidência.
4. **Multiusuário desde o início.** Toda tabela de dados pessoais tem
   `user_id`; acesso sempre filtrado por policies/escopos no backend.
5. **Privacidade por padrão.** Coleta mínima, consentimento por
   dispositivo e por tipo de dado, retenção configurável, exportação e exclusão.

## 4. Fluxos principais (planejados)

**Conversa com o NEXO**
1. Dashboard → `POST /api/nexo/messages` (autenticado).
2. Backend monta o contexto autorizado (perfil, rotina, memórias
   relevantes via pgvector) respeitando as permissões do usuário.
3. `NexoGateway` → ai-service → Ollama.
4. Backend persiste a mensagem/decisões relevantes (se o usuário permitir) e responde.

**Evento de atividade de dispositivo**
1. Agente registra evento localmente (fila) → envia em lote quando online.
2. Backend valida o token do dispositivo, deduplica (id idempotente) e grava.
3. Classificação contextual é assíncrona (fila de jobs); falha na IA não
   bloqueia o registro.

**Bloqueio de foco**
1. Regras são definidas no backend e sincronizadas para os agentes.
2. O agente decide localmente (sem chamada de rede no caminho crítico).
3. Liberações excepcionais e bloqueios indevidos são registrados e
   auditáveis; sempre existe um mecanismo de recuperação local.

## 5. Comunicação e rede

- Fase local: dispositivos → backend pela LAN, com TLS e token por dispositivo.
- Ollama e PostgreSQL: apenas `127.0.0.1` (containers usam `host.docker.internal`).
- Acesso remoto futuro somente via VPN (ex.: WireGuard/Tailscale) ou gateway
  autenticado — nunca porta aberta diretamente. Requer novo ADR.

## 6. Hardware de referência (servidor local)

Ver [docs/diagnostics/2026-11-16-fase1.md](docs/diagnostics/2026-11-16-fase1.md).
Resumo: i5-10400 (6c/12t), 16 GB RAM, RX 5700 XT 8 GB, SSD 512 GB.
Modelo local: ver [ADR-0004](docs/decisions/0004-modelo-local.md).

## 7. Decisões registradas

- [ADR-0001](docs/decisions/0001-monorepo-e-monolito-modular.md) — Monorepo, monólito modular + serviço de IA
- [ADR-0002](docs/decisions/0002-ambiente-docker-e-ollama-nativo.md) — Docker para infraestrutura, Ollama nativo
- [ADR-0003](docs/decisions/0003-nexo-ai-gateway.md) — NEXO AI Gateway e memória fora do modelo
- [ADR-0004](docs/decisions/0004-modelo-local.md) — Modelos locais escolhidos
- [ADR-0005](docs/decisions/0005-autenticacao-e-dispositivos.md) — Autenticação de usuários e dispositivos (+ ciclo de vida dos tokens)
- [ADR-0006](docs/decisions/0006-retencao-e-exclusao-de-conta.md) — Retenção de auditoria e exclusão de conta
- [ADR-0007](docs/decisions/0007-servico-de-ia-contrato-e-resiliencia.md) — Serviço de IA: contrato, segurança e resiliência
- [ADR-0008](docs/decisions/0008-memoria-do-nexo.md) — Memória do NEXO: histórico, memória de longo prazo e contexto temporário (aceita; ver [arquitetura da memória](docs/architecture/memoria-do-nexo.md))
- [ADR-0009](docs/decisions/0009-dashboard-react.md) — Dashboard React: arquitetura, sessão e testes
