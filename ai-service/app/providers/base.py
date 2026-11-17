"""Contrato entre a inteligência do NEXO e os provedores de modelo (ADR-0003).

Nada fora de `app/providers/` conhece Ollama: trocar de provedor (ex.: nuvem)
significa escrever outro adaptador que implemente `ModelProvider`.
"""

from dataclasses import dataclass
from typing import Any, Literal, Protocol

Role = Literal["system", "user", "assistant"]


@dataclass(frozen=True)
class ChatMessage:
    role: Role
    content: str


@dataclass(frozen=True)
class ChatResult:
    content: str  # já sem raciocínio interno
    model: str
    prompt_tokens: int | None = None
    output_tokens: int | None = None


@dataclass(frozen=True)
class EmbeddingResult:
    vectors: list[list[float]]
    model: str
    dimensions: int


class ProviderError(Exception):
    """Base dos erros de provedor. `code` é estável e exposto ao backend."""

    code = "ai_error"


class ProviderUnavailable(ProviderError):
    code = "ai_unavailable"


class ProviderTimeout(ProviderError):
    code = "ai_timeout"


class ProviderInvalidResponse(ProviderError):
    code = "ai_invalid_response"


class ModelProvider(Protocol):
    async def chat(
        self,
        messages: list[ChatMessage],
        *,
        temperature: float,
        max_output_tokens: int,
        response_format: dict[str, Any] | None = None,
    ) -> ChatResult:
        """`response_format`: JSON Schema que a resposta deve seguir (saída estruturada)."""
        ...

    async def embed(self, texts: list[str]) -> EmbeddingResult: ...

    async def is_available(self) -> bool: ...
