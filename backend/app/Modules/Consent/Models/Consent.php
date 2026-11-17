<?php

namespace App\Modules\Consent\Models;

use App\Modules\Consent\Enums\ConsentScope;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Estado atual de um consentimento. Ausência de linha = nunca decidido. */
#[Fillable(['user_id', 'scope', 'granted', 'policy_version'])]
class Consent extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'scope' => ConsentScope::class,
            'granted' => 'boolean',
        ];
    }
}
