<?php

namespace App\Modules\Memory\Models;

use App\Modules\Memory\Enums\MemoryKind;
use App\Modules\Memory\Policies\MemoryPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Proposta do NEXO que só vira memória com a confirmação da pessoa. */
#[WithoutTimestamps]
#[UsePolicy(MemoryPolicy::class)]
#[Fillable(['user_id', 'kind', 'content', 'rationale', 'source_conversation_id', 'source_message_id', 'expires_at', 'created_at'])]
class MemorySuggestion extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => MemoryKind::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
