<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\UnitCommandMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitCommandMapping>
 */
class UnitCommandMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'cav_position_id' => fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
