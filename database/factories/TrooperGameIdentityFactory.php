<?php

namespace Database\Factories;

use App\Models\Game;
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
            // Hell Let Loose, one of the games the migration creates; a test names another with `game_id`.
            'game_id' => fn () => Game::query()->where('slug', 'hll')->value('id'),
            'player_name' => fake()->userName(),
            'platform_id' => (string) fake()->unique()->numerify('7656119##########'),
            // The game's first kind, as the identity would take when none is chosen.
            'id_kind' => fn (array $attributes) => Game::query()->findOrFail($attributes['game_id'])->defaultIdKind(),
        ];
    }
}
