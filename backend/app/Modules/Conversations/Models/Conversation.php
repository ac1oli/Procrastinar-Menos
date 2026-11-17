<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Conversations\Policies\ConversationPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversa guardada no histórico (só com nexo.history, fora do modo privado). */
#[Fillable(['user_id', 'title', 'last_message_at', 'expires_at'])]
#[UsePolicy(ConversationPolicy::class)]
class Conversation extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
