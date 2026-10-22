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
        [$rankShort, $rank] = fake()->randomElement([['PVT', 'Private'], ['CPL', 'Corporal'], ['SGT', 'Sergeant']]);

        // `unique()` only remembers the ids it handed out in this PHP process. The browser tests seed in several
        // processes (one artisan call per seeder) into one database, so a narrow range let a later seeder draw an id
        // an earlier one had already used and fail on the UNIQUE index. A wide range makes that practically
        // impossible, and starting above a million keeps clear of the small ids tests pick by hand.
        return [
            'cav_id' => fake()->unique()->numberBetween(1_000_000, 2_000_000_000),
            'username' => fake()->lastName().'.'.strtoupper(fake()->randomLetter()),
            'forum_user_id' => fake()->unique()->numberBetween(1_000_000, 2_000_000_000),
            'rank' => $rank,
            'rank_short' => $rankShort,
            'billet' => fake()->randomElement(['Rifleman', 'Grenadier', 'Automatic Rifleman', 'Combat Medic']),
            'mos' => fake()->bothify('##?'),
            'tis' => fake()->date(),
            'tig' => fake()->date(),
            'status' => TrooperStatus::Combat,
        ];
    }
}
