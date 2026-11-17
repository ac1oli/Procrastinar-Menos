# ai-service — NEXO AI Service (Python + FastAPI)

> **Status:** fundação implementada e testada (FASE 3).

Camada de **inteligência** do NEXO: persona, montagem de prompts, remoção de raciocínio
interno e acesso a modelos via `ModelProvider` (hoje `OllamaProvider`). Não guarda
estado de usuário nem decide autorização. Quem faz isso é o backend
([ADR-0003](../docs/decisions/0003-nexo-ai-gateway.md),
[ADR-0007](../docs/decisions/0007-servico-de-ia-contrato-e-resiliencia.md)).

## Estrutura

```text
app/
  main.py             API (auth serviço-a-serviço, limites, mapeamento de erros)
  config.py           Configuração via variáveis AI_*
  schemas.py          Validação de entrada/saída (Pydantic, extra="forbid")
  nexo.py             Persona e montagem de mensagens; PROMPT_VERSION
  prompts/            Prompt de sistema versionado
  sanitize.py         Remoção de <think>…</think>
  limits.py           Limite de inferências simultâneas
  providers/
    base.py           ModelProvider + erros tipados
    ollama.py         Adaptador Ollama (/api/chat, /api/embed)
tests/                pytest (mocks; integração com Ollama real opcional)
```

## Executar e testar

O serviço roda no container `pm-ai-service` (compose da raiz):

```powershell
docker exec pm-ai-service python -m pytest -q                       # 43 testes com mocks
docker exec -e RUN_OLLAMA_TESTS=1 pm-ai-service python -m pytest -q -m integration   # Ollama real
docker exec pm-ai-service ruff check .
docker exec pm-ai-service ruff format --check .
```

## Configuração (`AI_*`)

| Variável | Padrão | Descrição |
|---|---|---|
| `AI_SERVICE_TOKEN` | — (obrigatória, ≥ 32) | Segredo compartilhado com o backend |
| `AI_OLLAMA_URL` | `http://127.0.0.1:11434` | No compose: `http://host.docker.internal:11434` |
| `AI_CHAT_MODEL` | `qwen3:8b` | Modelo de conversa ([ADR-0004](../docs/decisions/0004-modelo-local.md)) |
| `AI_EMBED_MODEL` | `qwen3-embedding:0.6b` | Modelo de embeddings (1024 dimensões) |
| `AI_CHAT_TIMEOUT` | `75` | Segundos de espera pelo Ollama |
| `AI_MAX_CONCURRENT_REQUESTS` | `2` | Inferências simultâneas |

## API

Todas as rotas `/v1/*` exigem `Authorization: Bearer $AI_SERVICE_TOKEN`.

| Rota | Entrada | Saída |
|---|---|---|
| `GET /health` | — | `{status, provider: up\|down}` |
| `POST /v1/nexo/reply` | `{message, context{timezone, local_datetime, memories[], profile?}, history[]}` | `{reply, model, prompt_version}` |
| `POST /v1/embed` | `{texts[], purpose: document\|query}` | `{model, dimensions, vectors}` |
| `POST /v1/nexo/suggest` | `{exchange[user, assistant], max_suggestions}` | `{suggestions[]{kind, content, rationale, sensitivity}, model, prompt_version}` |

Contrato completo e limites: [ADR-0007](../docs/decisions/0007-servico-de-ia-contrato-e-resiliencia.md).
Memórias e perfil entram no prompt como dados delimitados (nunca como instrução). O serviço
não guarda nada. Depois de mudar o código, reinicie o container (`docker restart pm-ai-service`):
o uvicorn roda sem recarga automática.

Erros: `{error, message}`, com `ai_unavailable`/`ai_busy` (503), `ai_timeout` (504) e
`ai_invalid_response` (502).
