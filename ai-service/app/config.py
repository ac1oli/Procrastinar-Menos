from functools import lru_cache

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Configuração via variáveis de ambiente (prefixo AI_)."""

    model_config = SettingsConfigDict(env_prefix="AI_", env_file=".env", extra="ignore")

    # Segredo compartilhado com o backend Laravel (Authorization: Bearer).
    service_token: SecretStr = Field(min_length=32)

    ollama_url: str = "http://127.0.0.1:11434"
    chat_model: str = "qwen3:8b"
    embed_model: str = "qwen3-embedding:0.6b"
    embed_dimensions: int = 1024

    # Timeouts em segundos. O do Ollama é menor que o do backend para que o
    # ai-service sempre responda com um erro estruturado antes do cliente desistir.
    connect_timeout: float = 2.0
    chat_timeout: float = 75.0
    embed_timeout: float = 30.0
    health_timeout: float = 2.0

    chat_context_tokens: int = 4096
    chat_max_output_tokens: int = 512

    # Chamadas simultâneas ao modelo (uma GPU de 8 GB não ganha com mais).
    max_concurrent_requests: int = 2
    queue_timeout: float = 10.0


@lru_cache
def get_settings() -> Settings:
    return Settings()
