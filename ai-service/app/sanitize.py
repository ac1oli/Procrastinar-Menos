"""Remoção do raciocínio interno do modelo antes de qualquer resposta ao usuário.

Modelos da família Qwen3 podem emitir o raciocínio entre <think>…</think> mesmo
com `think=false` (observado no qwen3:4b, ver ADR-0004). Casos tratados:

- bloco completo:       "<think>…</think>resposta"
- abertura ausente:     "…raciocínio…</think>resposta"
- bloco não fechado:    "resposta<think>…"  (tudo após <think> é descartado)
"""

import re

_BLOCK = re.compile(r"<think>.*?</think>", re.IGNORECASE | re.DOTALL)
_CLOSE = re.compile(r"</think>", re.IGNORECASE)
_OPEN = re.compile(r"<think>", re.IGNORECASE)


def strip_reasoning(text: str) -> str:
    text = _BLOCK.sub("", text)

    # Fechamento órfão: tudo antes dele era raciocínio.
    closes = list(_CLOSE.finditer(text))
    if closes:
        text = text[closes[-1].end() :]

    # Abertura sem fechamento: tudo depois dela é raciocínio.
    opening = _OPEN.search(text)
    if opening:
        text = text[: opening.start()]

    return text.strip()
