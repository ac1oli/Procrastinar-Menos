from typing import Annotated
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator

MAX_MESSAGE_CHARS = 4000
MAX_EMBED_TEXTS = 32
MAX_EMBED_TEXT_CHARS = 8000

NonBlank = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class ReplyContextIn(StrictModel):
    timezone: str = Field(max_length=64)
    local_datetime: str = Field(max_length=40)

    @field_validator("timezone")
    @classmethod
    def valid_timezone(cls, value: str) -> str:
        try:
            ZoneInfo(value)
        except (ZoneInfoNotFoundError, ValueError) as exc:
            raise ValueError("fuso horário inválido") from exc
        return value


class ReplyRequest(StrictModel):
    message: Annotated[NonBlank, StringConstraints(max_length=MAX_MESSAGE_CHARS)]
    context: ReplyContextIn


class ReplyResponse(BaseModel):
    reply: str
    model: str
    prompt_version: str


class EmbedRequest(StrictModel):
    texts: list[Annotated[NonBlank, StringConstraints(max_length=MAX_EMBED_TEXT_CHARS)]] = Field(
        min_length=1, max_length=MAX_EMBED_TEXTS
    )


class EmbedResponse(BaseModel):
    model: str
    dimensions: int
    vectors: list[list[float]]


class HealthResponse(BaseModel):
    status: str
    provider: str


class ErrorResponse(BaseModel):
    error: str
    message: str
