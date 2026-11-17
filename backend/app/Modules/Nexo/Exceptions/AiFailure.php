<?php

namespace App\Modules\Nexo\Exceptions;

enum AiFailure: string
{
    /** ai-service ou Ollama fora do ar. */
    case Unavailable = 'unavailable';

    /** O modelo demorou além do limite. */
    case Timeout = 'timeout';

    /** Fila de inferência cheia. */
    case Busy = 'busy';

    /** Resposta fora do contrato (formato, vazia, só raciocínio…). */
    case InvalidResponse = 'invalid_response';

    /** Token/URL incorretos ou contrato quebrado entre backend e ai-service. */
    case Misconfigured = 'misconfigured';
}
