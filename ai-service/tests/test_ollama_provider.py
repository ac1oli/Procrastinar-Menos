import json

import httpx
import pytest

from app.providers.base import ChatMessage, ProviderInvalidResponse, ProviderTimeout, ProviderUnavailable
from app.providers.ollama import OllamaProvider
from tests.conftest import make_settings

MESSAGES = [ChatMessage(role="user", content="Olá")]


def provider_with(handler, **settings) -> tuple[OllamaProvider, list[httpx.Request]]:
    seen: list[httpx.Request] = []

    def wrapped(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        return handler(request)

    client = httpx.AsyncClient(transport=httpx.MockTransport(wrapped))
    return OllamaProvider(make_settings(**settings), client), seen


async def test_chat_sends_expected_payload_and_parses_reply() -> None:
    provider, seen = provider_with(
        lambda r: httpx.Response(
            200, json={"model": "qwen3:8b", "message": {"content": "Oi!"}, "eval_count": 3}
        )
    )

    result = await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=128)

    assert result.content == "Oi!"
    assert result.output_tokens == 3
    body = json.loads(seen[0].content)
    assert seen[0].url.path == "/api/chat"
    assert body["model"] == "qwen3:8b"
    assert body["think"] is False
    assert body["stream"] is False
    assert body["options"]["num_predict"] == 128


async def test_chat_strips_leaked_reasoning_and_ignores_thinking_field() -> None:
    provider, _ = provider_with(
        lambda r: httpx.Response(
            200,
            json={"message": {"content": "<think>segredo</think>Resposta limpa.", "thinking": "segredo"}},
        )
    )

    result = await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)

    assert result.content == "Resposta limpa."
    assert "segredo" not in result.content


async def test_chat_forwards_structured_output_schema() -> None:
    provider, seen = provider_with(lambda r: httpx.Response(200, json={"message": {"content": "{}"}}))

    await provider.chat(MESSAGES, temperature=0.2, max_output_tokens=64, response_format={"type": "object"})
    await provider.chat(MESSAGES, temperature=0.2, max_output_tokens=64)

    assert json.loads(seen[0].content)["format"] == {"type": "object"}
    assert "format" not in json.loads(seen[1].content)


async def test_chat_with_only_reasoning_is_invalid() -> None:
    provider, _ = provider_with(
        lambda r: httpx.Response(200, json={"message": {"content": "<think>x</think>"}})
    )

    with pytest.raises(ProviderInvalidResponse):
        await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)


@pytest.mark.parametrize(
    "response",
    [
        httpx.Response(200, text="não é json"),
        httpx.Response(200, json=["lista"]),
        httpx.Response(200, json={"message": {}}),
        httpx.Response(400, json={"error": "bad request"}),
    ],
)
async def test_chat_invalid_responses(response: httpx.Response) -> None:
    provider, _ = provider_with(lambda r: response)

    with pytest.raises(ProviderInvalidResponse):
        await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)


@pytest.mark.parametrize("status_code", [404, 500, 503])
async def test_chat_server_errors_mean_unavailable(status_code: int) -> None:
    provider, _ = provider_with(lambda r: httpx.Response(status_code, json={"error": "x"}))

    with pytest.raises(ProviderUnavailable):
        await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)


async def test_chat_connection_error_means_unavailable() -> None:
    def refuse(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("recusado", request=request)

    provider, _ = provider_with(refuse)

    with pytest.raises(ProviderUnavailable):
        await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)


async def test_chat_timeout() -> None:
    def slow(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("lento", request=request)

    provider, _ = provider_with(slow)

    with pytest.raises(ProviderTimeout):
        await provider.chat(MESSAGES, temperature=0.5, max_output_tokens=64)


async def test_embed_validates_count_and_dimensions() -> None:
    provider, _ = provider_with(
        lambda r: httpx.Response(200, json={"embeddings": [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]}),
        embed_dimensions=3,
    )

    result = await provider.embed(["a", "b"])

    assert result.dimensions == 3
    assert result.vectors[1] == [0.4, 0.5, 0.6]


@pytest.mark.parametrize(
    "payload",
    [
        {"embeddings": [[0.1, 0.2]]},  # dimensão errada
        {"embeddings": [[0.1, 0.2, 0.3]]},  # quantidade errada (2 textos)
        {"embeddings": [[0.1, "x", 0.3], [0.1, 0.2, 0.3]]},  # valor não numérico
        {},
    ],
)
async def test_embed_rejects_malformed_vectors(payload: dict) -> None:
    provider, _ = provider_with(lambda r: httpx.Response(200, json=payload), embed_dimensions=3)

    with pytest.raises(ProviderInvalidResponse):
        await provider.embed(["a", "b"])


async def test_is_available_reflects_ollama_status() -> None:
    up, _ = provider_with(lambda r: httpx.Response(200, json={"version": "x"}))
    assert await up.is_available() is True

    def refuse(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("recusado", request=request)

    down, _ = provider_with(refuse)
    assert await down.is_available() is False
