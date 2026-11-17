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
        // Embedding da pergunta na recuperação: curto, porque a resposta espera
        // por ele; se estourar, a recuperação segue só com busca textual.
        'query_embed_timeout' => (float) env('NEXO_AI_QUERY_EMBED_TIMEOUT', 4),
        'suggest_timeout' => (float) env('NEXO_AI_SUGGEST_TIMEOUT', 90),
        'health_timeout' => (float) env('NEXO_AI_HEALTH_TIMEOUT', 2),
    ],

    'embedding_dimensions' => 1024,

    // Modelo de embedding ativo; vetores de outro modelo não são usados (ADR-0008 §2.4).
    'embedding_model' => env('NEXO_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),

    // Deve coincidir com MAX_MESSAGE_CHARS do ai-service.
    'max_message_chars' => 4000,

    // Mensagens ao NEXO por usuário por minuto.
    'messages_per_minute' => (int) env('NEXO_MESSAGES_PER_MINUTE', 10),

    // Segundos sugeridos ao cliente (Retry-After) quando a IA está indisponível.
    'retry_after' => 30,

    // Versão do texto da política que a pessoa precisa ter lido para autorizar.
    'memory_policy_version' => 'politica-de-memoria@1.0',

    'history' => [
        // Opções de retenção (dias; null = até a pessoa excluir) e o padrão (D2).
        'retention_options' => [30, 90, 365, null],
        'default_retention_days' => 90,
        // Turnos anteriores enviados como contexto temporário.
        'recent_turns' => 6,
        'recent_turns_max_chars' => 6000,
    ],

    'memory' => [
        'max_content_chars' => 500,
        'max_pinned' => 5,
        // Revisão sugerida após este período sem uso nem revisão (D3).
        'review_after_days' => 180,
        // Sugestões pendentes expiram (D10).
        'suggestion_ttl_days' => 14,
        'max_pending_suggestions' => 20,
        'suggestions_per_exchange' => 2,

        /*
        | Recuperação (ADR-0008 §2.5). Valores iniciais, a calibrar com o conjunto
        | de avaliação; não são resultados medidos.
        */
        'retrieval' => [
            'candidates' => 20,
            'max_results' => 6,
            'min_similarity' => 0.35,
            'sensitive_min_similarity' => 0.5,
            'rrf_k' => 60,
            'budget_chars' => 2400,
        ],
    ],

    'export' => [
        'ttl_hours' => 24,
    ],

    'backup' => [
        // Janela do diário de exclusões: igual à rotação máxima dos backups (D9).
        'deletion_journal_days' => 35,
    ],

];
