<?php

namespace App\Modules\Nexo\Contracts;

use App\Modules\Nexo\Data\Embeddings;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Exceptions\AiUnavailableException;

/**
 * Único ponto de contato do backend com a inteligência do NEXO (ADR-0003).
 *
 * Regras de negócio dependem desta interface, nunca do ai-service ou do
 * Ollama. Toda falha de IA chega como AiUnavailableException, para que o
 * chamador degrade com elegância em vez de quebrar.
 */
interface NexoGateway
{
    /** @throws AiUnavailableException */
    public function reply(string $message, ReplyContext $context): NexoReply;

    /**
     * @param  list<string>  $texts
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts): Embeddings;

    /** Verificação rápida; nunca lança exceção. */
    public function isAvailable(): bool;
}
