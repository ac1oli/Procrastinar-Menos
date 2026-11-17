import asyncio
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager


class ServiceBusy(Exception):
    code = "ai_busy"


class ConcurrencyLimiter:
    """Limita chamadas simultâneas ao modelo; quem espera demais recebe 'ocupado'."""

    def __init__(self, max_concurrent: int, queue_timeout: float) -> None:
        self._semaphore = asyncio.Semaphore(max_concurrent)
        self._queue_timeout = queue_timeout

    @asynccontextmanager
    async def slot(self) -> AsyncIterator[None]:
        try:
            await asyncio.wait_for(self._semaphore.acquire(), timeout=self._queue_timeout)
        except TimeoutError as exc:
            raise ServiceBusy("fila de inferência cheia") from exc
        try:
            yield
        finally:
            self._semaphore.release()
