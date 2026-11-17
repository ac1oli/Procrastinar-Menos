# ai-service — Python + FastAPI

> **Status:** não inicializado. Será criado na FASE 3.

Serviço especializado de IA do NEXO: orquestra prompts, recuperação
contextual (RAG sobre pgvector), embeddings e classificação de conteúdo.
Fala com provedores de modelo por adaptadores (Ollama primeiro).

Não guarda estado de negócio: a memória do NEXO vive no PostgreSQL,
controlada pelo backend. Ver [ADR-0003](../docs/decisions/0003-nexo-ai-gateway.md)
e [ADR-0004](../docs/decisions/0004-modelo-local.md).
