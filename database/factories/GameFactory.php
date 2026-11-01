<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\TelemetryInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A game added after the four the migrations create (hll, arma, wardogs, hll-vietnam): tests reach those by slug.
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
            'abbreviation' => fake()->unique()->regexify('Z[A-Z0-9]{4}'),
            'retired_at' => null,
        ];
    }

    public function retired(): static
    {
        return $this->state(['retired_at' => now()]);
    }
}
