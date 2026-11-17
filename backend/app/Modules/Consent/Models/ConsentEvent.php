<?php

namespace App\Modules\Consent\Models;

use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Consent\Enums\DataAction;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Histórico append-only: prova de quando e sob qual texto a pessoa decidiu. */
#[WithoutTimestamps]
#[Fillable(['user_id', 'scope', 'action', 'policy_version', 'data_action', 'created_at'])]
class ConsentEvent extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'scope' => ConsentScope::class,
            'data_action' => DataAction::class,
            'created_at' => 'datetime',
        ];
    }
}
