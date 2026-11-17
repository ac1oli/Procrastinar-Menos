<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Goals\Enums\GoalType;
use App\Modules\Goals\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'type' => fake()->randomElement(GoalType::cases()),
        ];
    }
}
