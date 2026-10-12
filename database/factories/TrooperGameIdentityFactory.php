<?php

namespace Database\Factories;

use App\Models\GamePlatform;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrooperGameIdentity>
 */
class TrooperGameIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trooper_id' => Trooper::factory(),
            'platform' => GamePlatform::Hll,
            'player_name' => fake()->userName(),
            'platform_id' => (string) fake()->unique()->numerify('7656119#########'),
        ];
    }
}
