<?php

return [

    /*
    | Conexão com o ai-service (NEXO AI Gateway, ADR-0003). No Docker, URL e
    | token são injetados pelo compose a partir de infrastructure/docker/.env.
    */
    'ai_service' => [
        'url' => env('NEXO_AI_SERVICE_URL', 'http://127.0.0.1:8100'),
        'token' => env('NEXO_AI_SERVICE_TOKEN'),
        'connect_timeout' => (float) env('NEXO_AI_CONNECT_TIMEOUT', 2),
        // Maior que o timeout do ai-service com o Ollama (75 s), para receber
        // o erro estruturado dele em vez de desistir antes.
        'reply_timeout' => (float) env('NEXO_AI_REPLY_TIMEOUT', 90),
        'embed_timeout' => (float) env('NEXO_AI_EMBED_TIMEOUT', 40),
        'health_timeout' => (float) env('NEXO_AI_HEALTH_TIMEOUT', 2),
    ],

    'embedding_dimensions' => 1024,

    // Deve coincidir com MAX_MESSAGE_CHARS do ai-service.
    'max_message_chars' => 4000,

    // Mensagens ao NEXO por usuário por minuto.
    'messages_per_minute' => (int) env('NEXO_MESSAGES_PER_MINUTE', 10),

    // Segundos sugeridos ao cliente (Retry-After) quando a IA está indisponível.
    'retry_after' => 30,

];
