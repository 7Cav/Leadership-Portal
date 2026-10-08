<?php

namespace Database\Factories;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trooper>
 */
class TrooperFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cav_id' => fake()->unique()->numberBetween(1, 100000),
            'username' => fake()->unique()->userName(),
            'rank' => fake()->randomElement(['Private', 'Corporal', 'Sergeant']),
            'mos' => fake()->bothify('##?'),
            'tis' => fake()->date(),
            'tig' => fake()->date(),
            'status' => TrooperStatus::Combat,
        ];
    }
}
