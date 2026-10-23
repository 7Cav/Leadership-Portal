<?php

namespace Database\Seeders;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * Sections whose practice this week has nobody marked yet, for the browser test of marks held for
 * Undo ("Mark all present", "Mark the rest missed") surviving the leader leaving the step or the
 * page. Seeded by the e2e setup beside the other seeders under its own company and usernames. A held
 * mark can only be checked once (a saved practice mark cannot be cleared), so each way of leaving
 * gets its own section and leader: `Quinn.A` (next step, then browser Back), `Novak.B` (next step),
 * `Ortiz.C` (a link to another week) and `Patel.D` (closing the tab). Refuses to run outside local,
 * testing and e2e: its users lead real units by billet.
 */
class RollcallHeldMarksSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    /** Section callsign => [leader, the two troopers left to mark]. */
    private const SECTIONS = [
        '1/1/E/1-7' => ['Quinn.A', ['Abara.N', 'Berg.T']],
        '2/1/E/1-7' => ['Novak.B', ['Castro.L', 'Dahl.S']],
        '3/1/E/1-7' => ['Ortiz.C', ['Egan.P', 'Frey.M']],
        '4/1/E/1-7' => ['Patel.D', ['Gallo.R', 'Holm.K']],
    ];

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $now = CarbonImmutable::now('UTC');
        $tag = $now->format('His').random_int(100, 999);

        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Echo Company', 'callsign' => 'E/1-7', 'path' => "7{$tag}/"]);
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/E/1-7', 'parent_id' => $company->id, 'path' => "7{$tag}/1/"]);

        $s = 0;
        foreach (self::SECTIONS as $callsign => [$leader, $troopers]) {
            $s++;
            $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => "Section {$s}", 'callsign' => $callsign, 'parent_id' => $platoon->id, 'path' => "7{$tag}/1/{$s}/"]);

            foreach ([$leader, ...$troopers] as $i => $username) {
                $cavId = (int) ($tag.$s.$i);
                User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
                Trooper::factory()->create([
                    'cav_id' => $cavId, 'rank_short' => $i === 0 ? 'SGT' : 'PFC', 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
                ]);
            }

            // The leader holds the section's command billet: the roster-derived scope, which writes.
            $position = (int) "{$tag}8{$s}";
            UnitCommandMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => $position]);
            Trooper::query()->where('cav_id', (int) ($tag.$s.'0'))->sole()->forceFill(['cav_position_id' => $position])->save();

            // This week's practice exists with no marks at all.
            app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        }
    }
}
