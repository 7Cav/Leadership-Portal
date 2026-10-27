<?php

namespace Database\Seeders;

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\PlacementSource;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * A transfer for the attendance-history browser test (decided 2026-10-27), under its own company and usernames.
 * `Okafor.B` leads 1st Section of Echo Company (1/1/E/1-7) by billet. `Brandt.L` served in 2nd Section (2/1/E/1-7),
 * signed and attended its practices two and three weeks ago, and moved to Okafor's section by the roster sync on the
 * Wednesday of last week. Okafor, the new leadership, sees those weeks and the move on the Sheet and the milpac.
 * Refuses to run outside local, testing and e2e: its user leads a section by command billet.
 */
class TransferHistorySeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $now = CarbonImmutable::now('UTC');
        $week = WeekOf::start($now);
        $tag = $now->format('His').random_int(100, 999);

        // Its own path prefix (5), apart from the other seeders' (6, 8, 9).
        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Echo Company', 'callsign' => 'E/1-7', 'path' => "5{$tag}/"]);
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/E/1-7', 'parent_id' => $company->id, 'path' => "5{$tag}/1/"]);
        $new = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/1/E/1-7', 'parent_id' => $platoon->id, 'path' => "5{$tag}/1/1/"]);
        $old = Unit::factory()->create(['type' => UnitType::Section, 'name' => '2nd Section', 'callsign' => '2/1/E/1-7', 'parent_id' => $platoon->id, 'path' => "5{$tag}/1/2/"]);

        $leader = $this->trooper('SGT', 'Okafor.B', (int) "{$tag}0", $new);
        User::factory()->create(['username' => 'Okafor.B', 'cav_id' => $leader->cav_id]);
        UnitCommandMapping::factory()->create(['unit_id' => $new->id, 'cav_position_id' => (int) "{$tag}95"]);
        $leader->forceFill(['cav_position_id' => (int) "{$tag}95"])->save();

        $mover = $this->trooper('PFC', 'Brandt.L', (int) "{$tag}1", $old);
        $practices = app(SectionPracticeRepository::class);
        $attendance = app(AttendanceRepository::class);

        foreach ([2, 3] as $back) {
            $practice = $practices->findOrCreate($old->id, $week->subWeeks($back));
            $attendance->markPractice($practice, $mover->id, AttendanceStatus::Present, AttendanceSource::Manual);
            $attendance->signRollcall($practice, $mover->id, RollcallSignature::WillAttend);
        }

        // The sync's move, as `TrooperRepository::updatePrimarySections` records it, dated last week.
        app(TrooperPlacementRepository::class)->record([$mover->id => [$old->id, $new->id]], PlacementSource::Sync, $week->subWeek()->addDays(4)->setTime(19, 0));
        $mover->forceFill(['primary_section_id' => $new->id, 'placement_source' => PlacementSource::Sync])->save();
    }

    private function trooper(string $rank, string $username, int $cavId, Unit $section): Trooper
    {
        return Trooper::factory()->create([
            'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
        ]);
    }
}
