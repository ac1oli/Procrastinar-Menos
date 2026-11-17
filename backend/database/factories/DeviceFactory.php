<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Devices\Enums\DevicePlatform;
use App\Modules\Devices\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'platform' => fake()->randomElement(DevicePlatform::cases()),
            'monitoring_enabled' => false,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
