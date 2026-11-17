# Imagem de desenvolvimento do ai-service (FastAPI). Não precisa de GPU:
# a inferência acontece no Ollama nativo do Windows (ADR-0002).
# O código é montado em /app pelo compose; a instalação editável aponta para lá.

FROM python:3.14-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY pyproject.toml ./
COPY app ./app
RUN pip install -e ".[dev]" \
    && useradd --create-home --uid 10001 nexo

USER nexo

EXPOSE 8100

CMD ["uvicorn", "app.main:create_app", "--factory", "--host", "0.0.0.0", "--port", "8100"]
