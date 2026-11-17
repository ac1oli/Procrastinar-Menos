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
