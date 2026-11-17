import pytest

from app.sanitize import strip_reasoning


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("Resposta direta.", "Resposta direta."),
        ("<think>pensando em inglês</think>\n\nResposta final.", "Resposta final."),
        ("<THINK>x</THINK>Resposta.", "Resposta."),
        # Caso real observado no qwen3:4b: sem a tag de abertura.
        ("Okay, the user said...\nLet me check.\n</think>\n\nEntendo seu cansaço.", "Entendo seu cansaço."),
        ("Resposta.<think>raciocínio sem fechamento", "Resposta."),
        ("<think>a</think>Parte 1 <think>b</think>Parte 2", "Parte 1 Parte 2"),
        ("<think>só raciocínio</think>", ""),
        ("<think>nunca fechou", ""),
    ],
)
def test_strip_reasoning(raw: str, expected: str) -> None:
    assert strip_reasoning(raw) == expected
