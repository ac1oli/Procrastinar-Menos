import logging
from typing import Any

import httpx

from app.config import Settings
from app.providers.base import (
    ChatMessage,
    ChatResult,
    EmbeddingResult,
    ProviderInvalidResponse,
    ProviderTimeout,
    ProviderUnavailable,
)
from app.sanitize import strip_reasoning

logger = logging.getLogger(__name__)


class OllamaProvider:
    """Adaptador para a API HTTP do Ollama (/api/chat, /api/embed)."""

    def __init__(self, settings: Settings, client: httpx.AsyncClient) -> None:
        self._settings = settings
        self._client = client

    async def chat(
        self, messages: list[ChatMessage], *, temperature: float, max_output_tokens: int
    ) -> ChatResult:
        payload = {
            "model": self._settings.chat_model,
            "messages": [{"role": m.role, "content": m.content} for m in messages],
            "stream": False,
            "think": False,
            "options": {
                "temperature": temperature,
                "num_ctx": self._settings.chat_context_tokens,
                "num_predict": max_output_tokens,
            },
        }
        data = await self._post("/api/chat", payload, self._settings.chat_timeout)

        message = data.get("message")
        raw = message.get("content") if isinstance(message, dict) else None
        if not isinstance(raw, str):
            raise ProviderInvalidResponse("resposta de chat sem message.content")

        # O campo message.thinking, se existir, é ignorado de propósito.
        content = strip_reasoning(raw)
        if not content:
            raise ProviderInvalidResponse("resposta vazia após remover o raciocínio")

        return ChatResult(
            content=content,
            model=str(data.get("model") or self._settings.chat_model),
            prompt_tokens=_int_or_none(data.get("prompt_eval_count")),
            output_tokens=_int_or_none(data.get("eval_count")),
        )

    async def embed(self, texts: list[str]) -> EmbeddingResult:
        payload = {"model": self._settings.embed_model, "input": texts}
        data = await self._post("/api/embed", payload, self._settings.embed_timeout)

        vectors = data.get("embeddings")
        expected = self._settings.embed_dimensions
        if (
            not isinstance(vectors, list)
            or len(vectors) != len(texts)
            or not all(isinstance(v, list) and len(v) == expected for v in vectors)
        ):
            raise ProviderInvalidResponse(f"embeddings ausentes ou fora de {expected} dimensões")

        try:
            clean = [[float(x) for x in v] for v in vectors]
        except (TypeError, ValueError) as exc:
            raise ProviderInvalidResponse("embedding com valor não numérico") from exc

        return EmbeddingResult(vectors=clean, model=self._settings.embed_model, dimensions=expected)

    async def is_available(self) -> bool:
        try:
            response = await self._client.get(
                f"{self._settings.ollama_url}/api/version", timeout=self._settings.health_timeout
            )
            return response.status_code == 200
        except httpx.HTTPError:
            return False

    async def _post(self, path: str, payload: dict[str, Any], read_timeout: float) -> dict[str, Any]:
        timeout = httpx.Timeout(read_timeout, connect=self._settings.connect_timeout)
        try:
            response = await self._client.post(
                f"{self._settings.ollama_url}{path}", json=payload, timeout=timeout
            )
        except httpx.TimeoutException as exc:
            raise ProviderTimeout(f"Ollama não respondeu em {read_timeout}s") from exc
        except httpx.HTTPError as exc:
            raise ProviderUnavailable("Ollama inacessível") from exc

        if response.status_code >= 500 or response.status_code == 404:
            # 404 do Ollama = modelo não instalado.
            logger.warning("Ollama respondeu HTTP %s em %s", response.status_code, path)
            raise ProviderUnavailable(f"Ollama respondeu HTTP {response.status_code}")
        if response.status_code != 200:
            raise ProviderInvalidResponse(f"Ollama respondeu HTTP {response.status_code}")

        try:
            data = response.json()
        except ValueError as exc:
            raise ProviderInvalidResponse("resposta do Ollama não é JSON") from exc
        if not isinstance(data, dict):
            raise ProviderInvalidResponse("resposta do Ollama não é um objeto")
        return data


def _int_or_none(value: object) -> int | None:
    return value if isinstance(value, int) else None
