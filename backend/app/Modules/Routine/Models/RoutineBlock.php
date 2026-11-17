<?php

namespace App\Modules\Routine\Models;

use App\Modules\Routine\Enums\ActivityCategory;
use App\Modules\Routine\Policies\RoutineBlockPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Database\Factories\RoutineBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Bloco recorrente da rotina planejada (ex.: "Estudo de Python, seg/qua 19h–21h").
 *
 * Representa o planejamento, não o que aconteceu: a rotina real virá de
 * registros e eventos de dispositivos.
 */
#[Fillable(['title', 'category', 'days_of_week', 'start_time', 'end_time', 'notes', 'is_active'])]
#[UseFactory(RoutineBlockFactory::class)]
#[UsePolicy(RoutineBlockPolicy::class)]
class RoutineBlock extends Model
{
    /** @use HasFactory<RoutineBlockFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'category' => ActivityCategory::class,
            'days_of_week' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function crossesMidnight(): bool
    {
        return $this->end_time < $this->start_time;
    }
}
