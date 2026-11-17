<?php

namespace App\Modules\Conversations\Listeners;

use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;
use App\Modules\Consent\Events\ConsentRevoked;
use App\Modules\Conversations\Models\Conversation;

/**
 * Revogação de nexo.history: "excluir" apaga todo o histórico (e, por
 * cascata, os derivados sem outra finalidade); "manter" não apaga nada, mas
 * nada novo é gravado nem usado (D7).
 */
class ApplyHistoryRevocation
{
    public function handle(ConsentRevoked $event): void
    {
        if ($event->scope === ConsentScope::History && $event->dataAction === DataAction::Delete) {
            Conversation::ownedBy($event->user)->delete();
        }
    }
}
