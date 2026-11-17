"""Formatação de textos para o modelo de embedding.

O qwen3-embedding é treinado com instrução na consulta e texto puro nos
documentos. O backend só informa o propósito; o formato fica aqui, perto do
modelo, para que trocar de modelo não afete o backend.
"""

from typing import Literal

from app.providers.base import EmbeddingResult, ModelProvider

# Instruções em inglês são as recomendadas pelo modelo, mesmo para textos em português.
QUERY_INSTRUCTION = (
    "Given a user's message to a personal productivity assistant, "
    "retrieve stored facts about the user that are relevant to answering it"
)


def format_texts(texts: list[str], purpose: Literal["document", "query"]) -> list[str]:
    if purpose == "query":
        return [f"Instruct: {QUERY_INSTRUCTION}\nQuery:{text}" for text in texts]
    return texts


async def embed(
    provider: ModelProvider, texts: list[str], purpose: Literal["document", "query"]
) -> EmbeddingResult:
    return await provider.embed(format_texts(texts, purpose))
