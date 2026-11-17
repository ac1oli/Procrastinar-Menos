<?php

namespace App\Modules\Memory\Listeners;

use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;
use App\Modules\Consent\Events\ConsentRevoked;
use App\Modules\Memory\Enums\MemorySensitivity;
use App\Modules\Memory\Models\Memory;

/**
 * Revogação de nexo.memory ou nexo.memory.sensitive (D7). "Excluir" apaga as
 * memórias e, por cascata, os embeddings; "manter" deixa tudo inativo: o
 * NEXO deixa de usar porque a recuperação exige o consentimento.
 */
class ApplyMemoryRevocation
{
    public function handle(ConsentRevoked $event): void
    {
        if ($event->dataAction !== DataAction::Delete) {
            return;
        }

        match ($event->scope) {
            ConsentScope::Memory => Memory::ownedBy($event->user)->delete(),
            ConsentScope::MemorySensitive => Memory::ownedBy($event->user)
                ->where('sensitivity', MemorySensitivity::Sensitive)
                ->delete(),
            default => null,
        };
    }
}
