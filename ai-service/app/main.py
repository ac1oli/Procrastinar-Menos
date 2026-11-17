"""API HTTP do ai-service. Chamada apenas pelo backend Laravel (NexoGateway)."""

import hmac
import logging
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager

import httpx
from fastapi import Depends, FastAPI, HTTPException, Request, status
from fastapi.responses import JSONResponse, Response
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app import embedding, nexo, suggest
from app.config import Settings, get_settings
from app.limits import ConcurrencyLimiter, ServiceBusy
from app.providers.base import ModelProvider, ProviderError
from app.providers.ollama import OllamaProvider
from app.schemas import (
    EmbedRequest,
    EmbedResponse,
    ErrorResponse,
    HealthResponse,
    ReplyRequest,
    ReplyResponse,
    SuggestRequest,
    SuggestResponse,
)

logger = logging.getLogger("nexo.ai")

MAX_BODY_BYTES = 512 * 1024

_ERROR_STATUS = {
    "ai_unavailable": status.HTTP_503_SERVICE_UNAVAILABLE,
    "ai_busy": status.HTTP_503_SERVICE_UNAVAILABLE,
    "ai_timeout": status.HTTP_504_GATEWAY_TIMEOUT,
    "ai_invalid_response": status.HTTP_502_BAD_GATEWAY,
}

_ERROR_MESSAGE = {
    "ai_unavailable": "O modelo de IA está indisponível.",
    "ai_busy": "O serviço de IA está ocupado. Tente novamente em instantes.",
    "ai_timeout": "O modelo de IA demorou demais para responder.",
    "ai_invalid_response": "O modelo de IA retornou uma resposta inválida.",
}


def create_app(settings: Settings | None = None, provider: ModelProvider | None = None) -> FastAPI:
    settings = settings or get_settings()
    limiter = ConcurrencyLimiter(settings.max_concurrent_requests, settings.queue_timeout)

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        if provider is not None:
            app.state.provider = provider
            yield
            return
        async with httpx.AsyncClient() as client:
            app.state.provider = OllamaProvider(settings, client)
            yield

    app = FastAPI(
        title="NEXO AI Service",
        version="0.1.0",
        lifespan=lifespan,
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    bearer = HTTPBearer(auto_error=False)
    expected_token = settings.service_token.get_secret_value().encode()

    def require_service_token(credentials: HTTPAuthorizationCredentials | None = Depends(bearer)) -> None:
        supplied = credentials.credentials.encode() if credentials else b""
        if not hmac.compare_digest(supplied, expected_token):
            raise HTTPException(status.HTTP_401_UNAUTHORIZED, detail="credencial de serviço inválida")

    def get_provider(request: Request) -> ModelProvider:
        return request.app.state.provider

    @app.middleware("http")
    async def limit_body_size(
        request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        length = request.headers.get("content-length")
        if length is not None and (not length.isdigit() or int(length) > MAX_BODY_BYTES):
            return JSONResponse(
                {"error": "payload_too_large", "message": "Requisição grande demais."},
                status_code=413,
            )
        return await call_next(request)

    @app.exception_handler(ProviderError)
    @app.exception_handler(ServiceBusy)
    async def handle_ai_error(_: Request, exc: ProviderError | ServiceBusy) -> JSONResponse:
        # O detalhe técnico vai para o log; o conteúdo do usuário, nunca.
        logger.warning("falha de IA: %s (%s)", exc.code, exc)
        body = ErrorResponse(error=exc.code, message=_ERROR_MESSAGE[exc.code])
        return JSONResponse(body.model_dump(), status_code=_ERROR_STATUS[exc.code])

    @app.get("/health", response_model=HealthResponse)
    async def health(provider: ModelProvider = Depends(get_provider)) -> HealthResponse:
        up = await provider.is_available()
        return HealthResponse(status="ok", provider="up" if up else "down")

    @app.post(
        "/v1/nexo/reply",
        response_model=ReplyResponse,
        dependencies=[Depends(require_service_token)],
    )
    async def nexo_reply(
        body: ReplyRequest, provider: ModelProvider = Depends(get_provider)
    ) -> ReplyResponse:
        profile = body.context.profile
        context = nexo.ReplyContext(
            timezone=body.context.timezone,
            local_datetime=body.context.local_datetime,
            memories=[nexo.Memory(kind=m.kind, content=m.content) for m in body.context.memories],
            profile=nexo.Profile(routine_today=profile.routine_today, goals=profile.goals)
            if profile
            else None,
        )
        async with limiter.slot():
            result = await nexo.reply(
                provider,
                body.message,
                context,
                max_output_tokens=settings.chat_max_output_tokens,
                history=[(turn.role, turn.content) for turn in body.history],
            )
        return ReplyResponse(reply=result.text, model=result.model, prompt_version=result.prompt_version)

    @app.post(
        "/v1/embed",
        response_model=EmbedResponse,
        dependencies=[Depends(require_service_token)],
    )
    async def embed(body: EmbedRequest, provider: ModelProvider = Depends(get_provider)) -> EmbedResponse:
        async with limiter.slot():
            result = await embedding.embed(provider, body.texts, body.purpose)
        return EmbedResponse(model=result.model, dimensions=result.dimensions, vectors=result.vectors)

    @app.post(
        "/v1/nexo/suggest",
        response_model=SuggestResponse,
        dependencies=[Depends(require_service_token)],
    )
    async def nexo_suggest(
        body: SuggestRequest, provider: ModelProvider = Depends(get_provider)
    ) -> SuggestResponse:
        by_role = {turn.role: turn.content for turn in body.exchange}
        if set(by_role) != {"user", "assistant"}:
            raise HTTPException(
                status.HTTP_422_UNPROCESSABLE_CONTENT, detail="exchange precisa de user e assistant"
            )
        async with limiter.slot():
            result = await suggest.suggest(
                provider, by_role["user"], by_role["assistant"], max_suggestions=body.max_suggestions
            )
        return SuggestResponse(
            suggestions=result.suggestions, model=result.model, prompt_version=result.prompt_version
        )

    return app
