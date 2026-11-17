"""Sugestões de memória de longo prazo a partir de uma troca de mensagens.

O modelo só propõe; o backend valida (sensibilidade, identificadores,
duplicatas) e nada é guardado sem a confirmação da pessoa (ADR-0008 §2.3).
"""

import hashlib
import json
from dataclasses import dataclass
from importlib import resources

from pydantic import BaseModel, ConfigDict, ValidationError

from app.nexo import as_data
from app.providers.base import ChatMessage, ModelProvider, ProviderInvalidResponse
from app.schemas import Suggestion

_PROMPT = resources.files("app.prompts").joinpath("memory_suggest.md").read_text(encoding="utf-8").strip()

PROMPT_VERSION = "memory-suggest@" + hashlib.sha256(_PROMPT.encode("utf-8")).hexdigest()[:12]

SUGGEST_TEMPERATURE = 0.2
SUGGEST_MAX_OUTPUT_TOKENS = 400

_KINDS = ["preferencia", "objetivo_pessoal", "conhecimento", "padrao", "contexto_de_vida", "restricao"]

# Saída estruturada exigida do modelo (Ollama `format`).
RESPONSE_SCHEMA = {
    "type": "object",
    "properties": {
        "suggestions": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "kind": {"type": "string", "enum": _KINDS},
                    "content": {"type": "string"},
                    "rationale": {"type": "string"},
                    "sensitivity": {"type": "string", "enum": ["normal", "sensitive"]},
                },
                "required": ["kind", "content", "rationale", "sensitivity"],
            },
        }
    },
    "required": ["suggestions"],
}


class _Payload(BaseModel):
    model_config = ConfigDict(extra="ignore")
    suggestions: list[Suggestion]


@dataclass(frozen=True)
class SuggestResult:
    suggestions: list[Suggestion]
    model: str
    prompt_version: str


def build_messages(user_text: str, assistant_text: str, max_suggestions: int) -> list[ChatMessage]:
    conversation = f"<conversa>\nPessoa: {as_data(user_text)}\nNEXO: {as_data(assistant_text)}\n</conversa>"
    return [
        ChatMessage(role="system", content=_PROMPT.replace("{max_suggestions}", str(max_suggestions))),
        ChatMessage(role="user", content=conversation),
    ]


async def suggest(
    provider: ModelProvider, user_text: str, assistant_text: str, *, max_suggestions: int
) -> SuggestResult:
    result = await provider.chat(
        build_messages(user_text, assistant_text, max_suggestions),
        temperature=SUGGEST_TEMPERATURE,
        max_output_tokens=SUGGEST_MAX_OUTPUT_TOKENS,
        response_format=RESPONSE_SCHEMA,
    )
    try:
        payload = _Payload.model_validate(json.loads(result.content))
    except (ValueError, ValidationError) as exc:
        raise ProviderInvalidResponse("sugestões fora do formato esperado") from exc

    return SuggestResult(
        suggestions=payload.suggestions[:max_suggestions], model=result.model, prompt_version=PROMPT_VERSION
    )
