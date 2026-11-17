<?php

namespace App\Modules\Nexo\Context;

use App\Models\User;
use App\Modules\Memory\Retrieval\MemoryRetriever;
use App\Modules\Memory\Retrieval\RetrievedMemory;
use App\Modules\Nexo\Data\ReplyContext;

/**
 * Monta o contexto temporário de uma mensagem (ADR-0008 §2.1, §2.5).
 * Nada daqui é gravado; cada fonte depende do próprio consentimento.
 */
final class ContextAssembler
{
    public function __construct(
        private readonly MemoryRetriever $retriever,
        private readonly ProfileSnapshot $profile,
    ) {}

    /** @return array{0: ReplyContext, 1: list<RetrievedMemory>} */
    public function assemble(User $user, string $message): array
    {
        $memories = $this->retriever->retrieve($user, $message);

        $context = ReplyContext::forUser($user)->with(
            array_map(fn (RetrievedMemory $m) => $m->forModel(), $memories),
            $this->profile->for($user),
        );

        return [$context, $memories];
    }
}
