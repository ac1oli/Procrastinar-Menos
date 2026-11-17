<?php

namespace App\Modules\Conversations\Models;

use App\Models\User;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Preferências de retenção do histórico (D2). Sem linha = padrão de 90 dias. */
#[Fillable(['user_id', 'history_retention_days'])]
class NexoPreference extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return ['history_retention_days' => 'integer'];
    }

    public static function historyRetentionDays(User $user): ?int
    {
        $preference = self::ownedBy($user)->first();

        return $preference ? $preference->history_retention_days : (int) config('nexo.history.default_retention_days');
    }
}
