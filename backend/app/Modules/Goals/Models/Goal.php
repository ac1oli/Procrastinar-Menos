<?php

namespace App\Modules\Goals\Models;

use App\Modules\Goals\Enums\GoalStatus;
use App\Modules\Goals\Enums\GoalType;
use App\Modules\Goals\Policies\GoalPolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'description', 'type', 'status', 'target_date'])]
#[UseFactory(GoalFactory::class)]
#[UsePolicy(GoalPolicy::class)]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => GoalType::class,
            'status' => GoalStatus::class,
            'target_date' => 'date',
        ];
    }
}
