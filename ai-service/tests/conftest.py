import pytest

from app.config import Settings
from app.providers.base import ChatMessage, ChatResult, EmbeddingResult, ProviderError

TOKEN = "t" * 40


def make_settings(**overrides) -> Settings:
    values = {"service_token": TOKEN, "ollama_url": "http://ollama.test"} | overrides
    return Settings(_env_file=None, **values)


class FakeProvider:
    """Provider em memória: devolve respostas fixas ou levanta o erro configurado."""

    def __init__(self, reply: str = "Resposta do NEXO.", error: ProviderError | None = None) -> None:
        self.reply = reply
        self.error = error
        self.available = True
        self.chat_calls: list[list[ChatMessage]] = []
        self.response_formats: list[dict | None] = []
        self.embed_calls: list[list[str]] = []

    async def chat(self, messages, *, temperature, max_output_tokens, response_format=None) -> ChatResult:
        self.chat_calls.append(messages)
        self.response_formats.append(response_format)
        if self.error:
            raise self.error
        return ChatResult(content=self.reply, model="fake-model")

    async def embed(self, texts) -> EmbeddingResult:
        self.embed_calls.append(texts)
        if self.error:
            raise self.error
        return EmbeddingResult(vectors=[[0.1, 0.2, 0.3] for _ in texts], model="fake-embed", dimensions=3)

    async def is_available(self) -> bool:
        return self.available


@pytest.fixture
def settings() -> Settings:
    return make_settings()


@pytest.fixture
def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}
