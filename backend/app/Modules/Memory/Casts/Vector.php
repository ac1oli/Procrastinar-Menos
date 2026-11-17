<?php

namespace App\Modules\Memory\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Converte entre list<float> e o literal de texto do pgvector ('[0.1,0.2,…]'). */
class Vector implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : array_map('floatval', explode(',', trim((string) $value, '[]')));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::literal($value);
    }

    /** @param list<float> $vector */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(fn ($x) => sprintf('%.8F', $x), $vector)).']';
    }
}
