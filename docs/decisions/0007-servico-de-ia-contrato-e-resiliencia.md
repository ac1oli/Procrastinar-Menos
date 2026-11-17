# ADR-0007 — Serviço de IA: contrato, segurança e resiliência

- **Status:** aceita
- **Data:** 2026-11-17
- **Complementa:** [ADR-0003](0003-nexo-ai-gateway.md)

## Contexto
O ADR-0003 definiu a separação entre backend (`NexoGateway`) e ai-service
(`ModelProvider`). Faltava definir onde o ai-service roda, como os dois se autenticam,
o contrato HTTP e o comportamento diante de timeouts, indisponibilidade e respostas
inválidas, sem que a IA derrube os recursos básicos.

## Decisão

### Separação de responsabilidades
| Camada | Responsável | Não faz |
|---|---|---|
| Regras de negócio | Backend (módulos de domínio) | Montar prompts |
| Contexto autorizado | Backend (`ReplyContext`) | — |
| Memória | Backend/PostgreSQL (ainda não implementada) | — |
| Inteligência (persona, prompts) | ai-service (`app/nexo.py`, `prompts/`) | Guardar estado; autorizar |
| Execução do modelo | `ModelProvider` → `OllamaProvider` | Conhecer o domínio |

Hoje o contexto enviado contém apenas o fuso e o horário local. Nome, e-mail e dados
de rotina ou objetivos só serão enviados com regras de consentimento (FASE 4).

### Implantação
- ai-service em **container** (`pm-ai-service`), sem GPU. Ele alcança o Ollama nativo via
  `host.docker.internal:11434`, que o Docker Desktop encaminha ao loopback do Windows.
  O Ollama continua sem exposição à rede (validado).
- O backend acessa `http://ai-service:8100` pela rede interna do compose. A porta no host
  fica só em `127.0.0.1:8100`, para diagnóstico.
- O backend **não** depende do ai-service no compose: sobe e funciona sem ele.

### Contrato HTTP (v1)
- `POST /v1/nexo/reply` `{message ≤ 4000, context{timezone, local_datetime}}` →
  `{reply, model, prompt_version}`.
- `POST /v1/embed` `{texts[1..32], cada ≤ 8000}` → `{model, dimensions, vectors}`.
- `GET /health` (público, sem detalhes) → `{status, provider: up|down}`.
- Erros: `{error, message}` com `ai_unavailable`/`ai_busy` (503), `ai_timeout` (504),
  `ai_invalid_response` (502). Campos extras são rejeitados (`extra="forbid"`).
- `prompt_version` = hash do prompt de sistema, para rastrear qual prompt gerou cada resposta.

### Segurança
- Autenticação serviço-a-serviço com segredo compartilhado (`AI_SERVICE_TOKEN`, ≥ 32
  caracteres), comparado em tempo constante. O segredo fica no `.env` da infraestrutura,
  fora do Git.
- Limite de corpo de 512 KiB. OpenAPI/docs desativados.
- O conteúdo das mensagens nunca vai para logs, em nenhum dos lados.

### Raciocínio interno
- `think=false` no Ollama, o campo `thinking` é ignorado e `<think>…</think>` é removido
  em **duas camadas** (ai-service e backend), cobrindo bloco completo, abertura ausente e
  bloco não fechado. Se sobrar resposta vazia, ela é tratada como inválida.

### Resiliência
| Situação | ai-service | Backend → usuário |
|---|---|---|
| Ollama fora do ar / modelo ausente | 503 `ai_unavailable` | 503 `unavailable` |
| Modelo lento (> 75 s) | 504 `ai_timeout` | 503 `timeout` |
| Fila cheia (2 simultâneas, espera 10 s) | 503 `ai_busy` | 503 `busy` |
| Resposta fora do contrato | 502 `ai_invalid_response` | 503 `invalid_response` |
| ai-service fora do ar / sem conexão | — | 503 `unavailable` |
| Token divergente / contrato violado | 401 / 422 | 503 `misconfigured` (log de erro) |

O usuário recebe `Retry-After: 30` e uma mensagem informando que rotinas, objetivos e
regras de foco continuam funcionando. O timeout do backend (90 s) é maior que o do
ai-service (75 s), para receber o erro estruturado.

### Limites de requisição
- Backend: 10 mensagens/min por usuário (`throttle:nexo`), além do limite geral da API.
- ai-service: concorrência máxima de 2 inferências (GPU de 8 GB).

## Alternativas avaliadas
- **Endpoint genérico `/v1/chat` com prompt vindo do backend:** espalharia a
  "inteligência" pelo backend. Rejeitado em favor de operações de domínio.
- **ai-service nativo no Windows:** exigiria gerenciar outro processo fora do compose.
  Rejeitado, porque o container alcança o Ollama sem expô-lo.
- **mTLS entre serviços:** desnecessário em rede interna de uma máquina. Revisar na nuvem (FASE 6).

## Consequências
- O primeiro pedido após o modelo ficar ocioso leva cerca de 25 s (carga na GPU);
  depois, cerca de 2–4 s.
- Mudanças no contrato exigem alterar os dois lados e seus testes juntos.
