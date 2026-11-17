"""Inteligência do NEXO: persona e montagem de mensagens.

Este módulo não sabe qual provedor executa o modelo, nem guarda estado.
O contexto chega pronto do backend, que decide o que pode ser usado.
"""

import hashlib
from dataclasses import dataclass
from importlib import resources

from app.providers.base import ChatMessage, ModelProvider

_SYSTEM_PROMPT = resources.files("app.prompts").joinpath("nexo_system.md").read_text(encoding="utf-8").strip()

# Muda automaticamente quando o texto do prompt muda; vai junto de cada resposta.
PROMPT_VERSION = "nexo-system@" + hashlib.sha256(_SYSTEM_PROMPT.encode("utf-8")).hexdigest()[:12]

REPLY_TEMPERATURE = 0.5


@dataclass(frozen=True)
class ReplyContext:
    timezone: str
    local_datetime: str


@dataclass(frozen=True)
class NexoReply:
    text: str
    model: str
    prompt_version: str


def build_messages(message: str, context: ReplyContext) -> list[ChatMessage]:
    context_block = (
        "Contexto fornecido pelo sistema (não pela pessoa):\n"
        f"- Fuso horário: {context.timezone}\n"
        f"- Data e hora locais: {context.local_datetime}"
    )
    return [
        ChatMessage(role="system", content=f"{_SYSTEM_PROMPT}\n\n{context_block}"),
        ChatMessage(role="user", content=message),
    ]


async def reply(
    provider: ModelProvider, message: str, context: ReplyContext, *, max_output_tokens: int
) -> NexoReply:
    result = await provider.chat(
        build_messages(message, context),
        temperature=REPLY_TEMPERATURE,
        max_output_tokens=max_output_tokens,
    )
    return NexoReply(text=result.content, model=result.model, prompt_version=PROMPT_VERSION)
