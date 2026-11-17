"""Testes contra o Ollama real. Opcionais: RUN_OLLAMA_TESTS=1 e AI_OLLAMA_URL."""

import os

import httpx
import pytest

from app import nexo
from app.providers.ollama import OllamaProvider
from tests.conftest import make_settings

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(os.getenv("RUN_OLLAMA_TESTS") != "1", reason="defina RUN_OLLAMA_TESTS=1"),
]


def real_provider(client: httpx.AsyncClient) -> OllamaProvider:
    return OllamaProvider(
        make_settings(ollama_url=os.getenv("AI_OLLAMA_URL", "http://127.0.0.1:11434")), client
    )


async def test_real_reply_in_portuguese_without_reasoning() -> None:
    async with httpx.AsyncClient() as client:
        result = await nexo.reply(
            real_provider(client),
            "Deixei de estudar Python por 3 dias porque trabalhei até tarde. O que faço?",
            nexo.ReplyContext(timezone="America/Sao_Paulo", local_datetime="2026-11-17T21:00:00-03:00"),
            max_output_tokens=300,
        )

    assert result.text
    assert "<think>" not in result.text.lower()
    assert "</think>" not in result.text.lower()


async def test_real_embeddings_have_expected_dimensions() -> None:
    async with httpx.AsyncClient() as client:
        result = await real_provider(client).embed(["Aula de Python", "Memes engraçados"])

    assert result.dimensions == 1024
    assert len(result.vectors) == 2


async def test_real_suggestions_follow_the_schema() -> None:
    from app import suggest

    async with httpx.AsyncClient() as client:
        result = await suggest.suggest(
            real_provider(client),
            "Rendo muito mais quando estudo logo cedo, antes do trabalho. À noite não consigo focar.",
            "Então vamos reservar suas manhãs para estudo e deixar a noite para descanso.",
            max_suggestions=2,
        )

    assert len(result.suggestions) <= 2
    for item in result.suggestions:
        assert item.content and len(item.content) <= 500
        assert item.sensitivity in {"normal", "sensitive"}
    print("\nsugestões reais:", [(s.kind, s.content, s.sensitivity) for s in result.suggestions])


async def test_real_query_embedding_ranks_related_memory_first() -> None:
    from app import embedding

    async with httpx.AsyncClient() as client:
        provider = real_provider(client)
        docs = await embedding.embed(
            provider,
            ["Prefere estudar no início da manhã.", "Gosta de jogar futebol aos domingos."],
            "document",
        )
        query = await embedding.embed(provider, ["Qual o melhor horário para eu estudar?"], "query")

    def cosine(a: list[float], b: list[float]) -> float:
        dot = sum(x * y for x, y in zip(a, b, strict=True))
        return dot / ((sum(x * x for x in a) ** 0.5) * (sum(y * y for y in b) ** 0.5))

    study, football = (cosine(query.vectors[0], v) for v in docs.vectors)
    print(f"\ncosseno estudo={study:.3f} futebol={football:.3f}")
    assert study > football
