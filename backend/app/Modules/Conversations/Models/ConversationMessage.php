<?php

namespace App\Modules\Conversations\Models;

use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem do histórico. O conteúdo é gravado já mascarado (D5). */
#[WithoutTimestamps]
#[Hidden(['content_tsv'])]
#[Fillable(['conversation_id', 'user_id', 'role', 'content', 'redaction_count', 'model', 'prompt_version', 'created_at'])]
class ConversationMessage extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'redaction_count' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
