<?php

namespace App\Modules\Memory\Jobs;

use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\Models\EmbeddingModel;
use App\Modules\Memory\Models\Memory;
use App\Modules\Memory\Models\MemoryEmbedding;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Shared\Database\DatabaseUserScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Gera ou substitui o embedding de uma memória (ADR-0008 §2.4).
 *
 * O payload da fila leva só identificadores, nunca o conteúdo. Se a IA
 * estiver fora, a memória continua salva e pesquisável por texto; o job
 * tenta de novo mais tarde e o nexo:reindex-memories cobre o que sobrar.
 */
class EmbedMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $userId,
        public readonly string $memoryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(NexoGateway $gateway, ConsentService $consents): void
    {
        DatabaseUserScope::run($this->userId, function () use ($gateway, $consents) {
            $memory = Memory::find($this->memoryId);
            if ($memory === null || ! $consents->allows($memory->user, ConsentScope::Memory)) {
                return;
            }

            $active = EmbeddingModel::active();
            $current = $memory->embedding;
            if ($current && $active && $current->embedding_model_id === $active->id && $current->content_hash === $memory->content_hash) {
                return;
            }

            try {
                $result = $gateway->embed([$memory->content]);
            } catch (AiUnavailableException) {
                $this->release($this->backoff()[min($this->attempts() - 1, 3)] ?? 1800);

                return;
            }

            try {
                MemoryEmbedding::updateOrCreate(['memory_id' => $memory->id], [
                    'user_id' => $memory->user_id,
                    'embedding_model_id' => EmbeddingModel::register($result->model, $result->dimensions)->id,
                    'content_hash' => $memory->content_hash,
                    'embedding' => $result->vectors[0],
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                // A mensagem original traz o vetor nos parâmetros; failed_jobs fica sem ele.
                throw new RuntimeException('Falha ao gravar embedding (SQLSTATE '.($e->errorInfo[0] ?? '?').').');
            }
        });
    }
}
