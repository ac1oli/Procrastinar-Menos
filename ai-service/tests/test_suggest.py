"""Sugestões de memória: o modelo propõe em JSON estruturado; nada é guardado aqui."""

import json

import pytest
from fastapi.testclient import TestClient

from app.main import create_app
from app.suggest import PROMPT_VERSION, RESPONSE_SCHEMA
from tests.conftest import FakeProvider, make_settings

EXCHANGE = [
    {"role": "user", "content": "Rendo muito mais estudando cedo, antes do trabalho."},
    {"role": "assistant", "content": "Ótimo! Vamos reservar as manhãs para estudo."},
]


def post_suggest(auth, provider: FakeProvider, body: dict | None = None):
    with TestClient(create_app(make_settings(), provider=provider)) as client:
        return client.post("/v1/nexo/suggest", json=body or {"exchange": EXCHANGE}, headers=auth)


def suggestion(**overrides) -> dict:
    return {
        "kind": "preferencia",
        "content": "Prefere estudar no início da manhã.",
        "rationale": "Você mencionou que rende mais cedo.",
        "sensitivity": "normal",
    } | overrides


def test_returns_validated_suggestions_with_structured_output(auth) -> None:
    provider = FakeProvider(reply=json.dumps({"suggestions": [suggestion()]}))

    response = post_suggest(auth, provider)

    assert response.status_code == 200
    assert response.json() == {
        "suggestions": [suggestion()],
        "model": "fake-model",
        "prompt_version": PROMPT_VERSION,
    }
    assert provider.response_formats == [RESPONSE_SCHEMA]
    conversation = provider.chat_calls[0][1].content
    assert "Pessoa: Rendo muito mais" in conversation
    assert "NEXO: Ótimo!" in conversation


def test_sensitive_label_is_passed_through_for_backend_to_discard(auth) -> None:
    provider = FakeProvider(reply=json.dumps({"suggestions": [suggestion(sensitivity="sensitive")]}))
    assert post_suggest(auth, provider).json()["suggestions"][0]["sensitivity"] == "sensitive"


def test_respects_max_suggestions(auth) -> None:
    provider = FakeProvider(reply=json.dumps({"suggestions": [suggestion()] * 3}))
    response = post_suggest(auth, provider, {"exchange": EXCHANGE, "max_suggestions": 1})
    assert len(response.json()["suggestions"]) == 1


@pytest.mark.parametrize(
    "reply",
    [
        "não é json",
        json.dumps({"outra": []}),
        json.dumps({"suggestions": [suggestion(kind="senha")]}),
        json.dumps({"suggestions": [suggestion(content="x" * 501)]}),
        json.dumps({"suggestions": [suggestion(sensitivity="talvez")]}),
    ],
)
def test_malformed_model_output_is_invalid_response(auth, reply: str) -> None:
    response = post_suggest(auth, FakeProvider(reply=reply))
    assert response.status_code == 502
    assert response.json()["error"] == "ai_invalid_response"


@pytest.mark.parametrize(
    "body",
    [
        {"exchange": EXCHANGE[:1]},
        {"exchange": [EXCHANGE[0], EXCHANGE[0]]},
        {"exchange": EXCHANGE, "max_suggestions": 4},
        {"exchange": EXCHANGE, "extra": True},
    ],
)
def test_validates_request(auth, body: dict) -> None:
    provider = FakeProvider()
    assert post_suggest(auth, provider, body).status_code == 422
    assert provider.chat_calls == []


def test_requires_service_token() -> None:
    with TestClient(create_app(make_settings(), provider=FakeProvider())) as client:
        assert client.post("/v1/nexo/suggest", json={"exchange": EXCHANGE}).status_code == 401
