from typing import Annotated, Literal
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator

MAX_MESSAGE_CHARS = 4000
MAX_EMBED_TEXTS = 32
MAX_EMBED_TEXT_CHARS = 8000

# Contexto temporário montado pelo backend (ADR-0008 §2.5). Os limites daqui
# são o teto do contrato; o backend aplica orçamentos menores.
MAX_HISTORY_TURNS = 12
MAX_MEMORIES = 12
MAX_MEMORY_CHARS = 500
MAX_ROUTINE_ITEMS = 12
MAX_GOALS = 5
MAX_PROFILE_ITEM_CHARS = 300
MAX_SUGGESTIONS = 3

MemoryKind = Literal[
    "preferencia", "objetivo_pessoal", "conhecimento", "padrao", "contexto_de_vida", "restricao"
]

NonBlank = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]
ProfileItem = Annotated[NonBlank, StringConstraints(max_length=MAX_PROFILE_ITEM_CHARS)]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class Turn(StrictModel):
    role: Literal["user", "assistant"]
    content: Annotated[NonBlank, StringConstraints(max_length=MAX_MESSAGE_CHARS)]


class MemoryIn(StrictModel):
    kind: MemoryKind
    content: Annotated[NonBlank, StringConstraints(max_length=MAX_MEMORY_CHARS)]


class ProfileIn(StrictModel):
    routine_today: list[ProfileItem] = Field(default_factory=list, max_length=MAX_ROUTINE_ITEMS)
    goals: list[ProfileItem] = Field(default_factory=list, max_length=MAX_GOALS)


class ReplyContextIn(StrictModel):
    timezone: str = Field(max_length=64)
    local_datetime: str = Field(max_length=40)
    memories: list[MemoryIn] = Field(default_factory=list, max_length=MAX_MEMORIES)
    profile: ProfileIn | None = None

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
    history: list[Turn] = Field(default_factory=list, max_length=MAX_HISTORY_TURNS)


class ReplyResponse(BaseModel):
    reply: str
    model: str
    prompt_version: str


class EmbedRequest(StrictModel):
    texts: list[Annotated[NonBlank, StringConstraints(max_length=MAX_EMBED_TEXT_CHARS)]] = Field(
        min_length=1, max_length=MAX_EMBED_TEXTS
    )
    # "query": texto de busca (recebe a instrução do modelo); "document": texto indexado.
    purpose: Literal["document", "query"] = "document"


class EmbedResponse(BaseModel):
    model: str
    dimensions: int
    vectors: list[list[float]]


class SuggestRequest(StrictModel):
    exchange: list[Turn] = Field(min_length=2, max_length=2)
    max_suggestions: int = Field(default=2, ge=1, le=MAX_SUGGESTIONS)


class Suggestion(StrictModel):
    kind: MemoryKind
    content: Annotated[NonBlank, StringConstraints(max_length=MAX_MEMORY_CHARS)]
    rationale: Annotated[str, StringConstraints(strip_whitespace=True, max_length=300)] = ""
    sensitivity: Literal["normal", "sensitive"]


class SuggestResponse(BaseModel):
    suggestions: list[Suggestion]
    model: str
    prompt_version: str


class HealthResponse(BaseModel):
    status: str
    provider: str


class ErrorResponse(BaseModel):
    error: str
    message: str
