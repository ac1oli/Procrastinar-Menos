<?php

namespace App\Modules\Nexo\Contracts;

use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Data\Embeddings;
use App\Modules\Nexo\Data\NexoReply;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Data\SuggestedMemory;
use App\Modules\Nexo\Data\Turn;
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
    /**
     * @param  list<Turn>  $history  turnos anteriores (contexto temporário)
     *
     * @throws AiUnavailableException
     */
    public function reply(string $message, ReplyContext $context, array $history = []): NexoReply;

    /**
     * @param  list<string>  $texts
     *
     * @throws AiUnavailableException
     */
    public function embed(array $texts, EmbeddingPurpose $purpose = EmbeddingPurpose::Document): Embeddings;

    /**
     * Propostas de memória a partir de uma troca. Não grava nada.
     *
     * @return list<SuggestedMemory>
     *
     * @throws AiUnavailableException
     */
    public function suggestMemories(Turn $user, Turn $assistant, int $max): array;

    /** Verificação rápida; nunca lança exceção. */
    public function isAvailable(): bool;
}
