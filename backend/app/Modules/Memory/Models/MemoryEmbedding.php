<?php

namespace App\Modules\Memory\Models;

use App\Modules\Memory\Casts\Vector;
use App\Modules\Shared\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/** Derivado da memória: mesmo dono, mesma exclusão (R8). Nunca é exportado. */
#[WithoutTimestamps]
#[Hidden(['embedding'])]
#[Fillable(['memory_id', 'user_id', 'embedding_model_id', 'content_hash', 'embedding', 'created_at'])]
class MemoryEmbedding extends Model
{
    use BelongsToUser;

    protected $primaryKey = 'memory_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'created_at' => 'datetime',
        ];
    }
}
