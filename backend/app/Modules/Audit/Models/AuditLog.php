<?php

namespace App\Modules\Audit\Models;

use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[WithoutTimestamps]
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'created_at'])]
class AuditLog extends Model
{
    use BelongsToUser, HasUuids;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
