<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Routine\Enums\ActivityCategory;
use App\Modules\Routine\Models\RoutineBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutineBlock>
 */
class RoutineBlockFactory extends Factory
{
    protected $model = RoutineBlock::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement(ActivityCategory::cases()),
            'days_of_week' => [1, 3, 5],
            'start_time' => '19:00',
            'end_time' => '21:00',
            'is_active' => true,
        ];
    }
}
