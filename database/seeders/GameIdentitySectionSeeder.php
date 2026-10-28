<?php

namespace Database\Seeders;

use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A section of its own for one game identities browser test: eight troopers linked to no game, under a leader who
 * holds its command billet. The spec seeds one before every test (tests/e2e/game-identities.spec.js), naming the
 * leader in `E2E_SECTION_LEADER`, so every test, retry and `--repeat-each` copy starts from the same state, and none
 * of them sees the links another wrote, however the runner schedules them. The section has no parent, so no company,
 * platoon or roll-up another spec reads changes. Refuses to run outside local, testing and e2e: its leader holds real
 * scope by billet.
 */
class GameIdentitySectionSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    /** Troopers in the section, so the list runs past a short screen the way a real one does. */
    public const STRENGTH = 8;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $leader = (string) env('E2E_SECTION_LEADER', '');
        if ($leader === '') {
            throw new RuntimeException('Set E2E_SECTION_LEADER to the username the new section\'s leader signs in with.');
        }

        $section = app(UnitRepository::class)->create(UnitType::Section, "Section {$leader}", "S/{$leader}", null);
        // The leader's own trooper is placed in no section, so the list holds just the eight.
        User::factory()->commanding($section, ['username' => $leader])->create(['username' => $leader]);

        for ($t = 0; $t < self::STRENGTH; $t++) {
            Trooper::factory()->create()->forceFill(['primary_section_id' => $section->id])->save();
        }
    }
}
