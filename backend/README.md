# backend — Laravel 13 (PHP 8.3+)

> **Status:** não inicializado. Será criado na FASE 3 (após WSL2/Docker operacionais).

Monólito modular responsável por regras de negócio, autenticação, usuários,
dispositivos, rotinas, objetivos, foco/bloqueios, central de conhecimento,
permissões, auditoria e APIs (REST + WebSockets via Laravel Reverb).

Integra-se à IA **somente** pelo NEXO AI Gateway (interface `AiProvider`),
nunca chamando o Ollama diretamente. Ver [ADR-0003](../docs/decisions/0003-nexo-ai-gateway.md).
