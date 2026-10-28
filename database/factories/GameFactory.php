<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\TelemetryInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A game added after the three the migration creates (hll, arma, wardogs): tests reach those by slug.
 *
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => str($name)->slug()->value(),
            'name' => ucwords($name),
            'id_kinds' => ['Player ID'],
            'interface' => TelemetryInterface::None,
            'retired_at' => null,
        ];
    }

    public function retired(): static
    {
        return $this->state(['retired_at' => now()]);
    }
}
