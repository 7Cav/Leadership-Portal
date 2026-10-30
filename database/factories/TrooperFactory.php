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
            // Derived from the cav_id, like UserFactory's for a linked user, so a user and a trooper made
            // separately for one cav_id share a forum identity, as a real milpac link does.
            'forum_user_id' => fn (array $attributes) => self::forumUserIdFor((int) $attributes['cav_id']),
            'rank' => $rank,
            'rank_short' => $rankShort,
            'billet' => fake()->randomElement(['Rifleman', 'Grenadier', 'Automatic Rifleman', 'Combat Medic']),
            'mos' => fake()->bothify('##?'),
            // Joined years before any week a test reads: a join date inside the KPI window excuses that week
            // automatically (`Kpi\AutoExcused`), so a test that wants a recruit sets `tis` itself.
            'tis' => fake()->dateTimeBetween('2000-01-01', '2019-12-31')->format('Y-m-d'),
            'tig' => fake()->date(),
            'status' => TrooperStatus::Combat,
        ];
    }

    /**
     * The forum user id a factory-made trooper and a user linked to it share by default. Offset past every
     * random and hand-picked id, so it never collides with another account's.
     */
    public static function forumUserIdFor(int $cavId): int
    {
        return 3_000_000_000 + $cavId;
    }
}
