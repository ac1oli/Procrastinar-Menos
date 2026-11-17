<?php

namespace App\Modules\Memory\Console;

use App\Models\User;
use App\Modules\Consent\ConsentService;
use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Memory\Jobs\EmbedMemory;
use App\Modules\Memory\Models\EmbeddingModel;
use App\Modules\Memory\Models\Memory;
use App\Modules\Shared\Database\DatabaseUserScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Enfileira embeddings faltantes ou desatualizados: memórias criadas com a IA
 * fora do ar, troca de modelo de embedding ou restauração de backup (os
 * vetores não entram no backup).
 */
#[Signature('nexo:reindex-memories {--user= : Somente este usuário (UUID)}')]
#[Description('Enfileira embeddings faltantes ou desatualizados das memórias do NEXO.')]
class ReindexMemories extends Command
{
    public function handle(ConsentService $consents): int
    {
        $activeId = EmbeddingModel::active()?->id;
        $queued = 0;

        User::query()->select('id')
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->lazyById(200)
            ->each(function (User $user) use ($consents, $activeId, &$queued) {
                DatabaseUserScope::run($user, function () use ($user, $consents, $activeId, &$queued) {
                    if (! $consents->allows($user, ConsentScope::Memory)) {
                        return;
                    }

                    Memory::ownedBy($user)
                        ->whereDoesntHave('embedding', fn ($q) => $q
                            ->where('embedding_model_id', $activeId)
                            ->whereColumn('memory_embeddings.content_hash', 'memories.content_hash'))
                        ->pluck('id')
                        ->each(function (string $id) use ($user, &$queued) {
                            EmbedMemory::dispatch($user->id, $id);
                            $queued++;
                        });
                });
            });

        $this->info("Embeddings enfileirados: {$queued}.");

        return self::SUCCESS;
    }
}
