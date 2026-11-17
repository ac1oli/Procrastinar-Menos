<?php

namespace App\Modules\Memory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Registro do modelo que gerou cada vetor; trocar de modelo exige reindexar. */
#[WithoutTimestamps]
#[Fillable(['name', 'dimensions', 'created_at'])]
class EmbeddingModel extends Model
{
    use HasUuids;

    public static function active(): ?self
    {
        return self::where('name', config('nexo.embedding_model'))->first();
    }

    public static function register(string $name, int $dimensions): self
    {
        return self::firstOrCreate(['name' => $name], ['dimensions' => $dimensions, 'created_at' => now()]);
    }
}
