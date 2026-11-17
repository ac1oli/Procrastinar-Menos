# ADR-0004 — Modelos locais do NEXO

- **Status:** aceita (reavaliar a cada nova geração de modelos)
- **Data:** 2026-11-16

## Contexto
Servidor local: Intel i5-10400, 16 GB DDR4-2666, **AMD RX 5700 XT 8 GB**
(Ollama 0.40.16 via Vulkan; ~7,2 GiB livres). O NEXO conversa em
português, precisa seguir instruções de formato e, no futuro, chamar
ferramentas (tool calling). Também precisa de embeddings para pgvector.

## Benchmark real (2026-11-16, `num_ctx=4096`, prompt de empatia/rotina em PT-BR)

| Modelo | Memória | Na GPU | Prompt | Geração | Observação |
|---|---|---|---|---|---|
| `qwen3:8b` (Q4_K_M) | 5,20 GB | 100% | 198,8 tok/s | **50,7 tok/s** | Respeitou `think=false`; resposta empática, concisa e em PT-BR |
| `qwen3:4b` | 2,96 GB | 100% | 105,7 tok/s | 86,0 tok/s | **Ignorou `think=false`**: vazou raciocínio em inglês com `</think>` na resposta (626 tokens para 4 frases) |
| `qwen3-embedding:0.6b` | — | — | — | — | Dimensão **1024**; cos(Python, pandas)=0,710 vs cos(Python, memes)=0,532 |

## Decisão
- **Chat/raciocínio:** `qwen3:8b` — cabe inteiro na VRAM com folga para
  contexto de 4–8k tokens e tem velocidade mais que suficiente para conversa.
- **Embeddings:** `qwen3-embedding:0.6b` — multilíngue, 1024 dimensões
  (`vector(1024)` no pgvector). O modelo usado é gravado junto de cada vetor.
- `qwen3:4b` **não** será usado por padrão. Pode ser removido
  (`ollama rm qwen3:4b`) ou mantido como candidato para classificação em
  lote, após nova validação.
- Os nomes dos modelos são **configuração** do ai-service, nunca hard-coded.

## Salvaguardas obrigatórias no ai-service
- Remover defensivamente qualquer bloco `<think>…</think>` da saída.
- Validar saídas estruturadas (JSON schema / Pydantic) e repetir/abortar
  em caso de formato inválido.
- Limitar `num_ctx` e `num_predict` por operação.

## Alternativas avaliadas
- Modelos 12–14B (Q4): ~8–9 GB, não cabem inteiros em 8 GB de VRAM →
  transbordo para CPU/RAM (16 GB, já disputada por Docker/WSL) e queda
  forte de velocidade. Rejeitado neste hardware.
- `nomic-embed-text`: bom em inglês, inferior em português. Rejeitado.

## Consequências
- Chat (5,2 GB) + embeddings (~1 GB) cabem juntos na VRAM com contexto moderado.
- Contextos longos (>8k) aumentam o KV cache e podem transbordar para CPU;
  o RAG deve enviar trechos selecionados, não documentos inteiros.
