<?php

namespace Database\Seeders;

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * Joint Service Achievement Medal evidence for the guests browser test (decided 2026-10-23), seeded by the e2e setup
 * under its own company and usernames. `Osei.K` leads 1st Section of Delta Company (1/1/D/1-7) by billet, so he may add
 * guests on its rollcall. `Faraday.M` serves in another platoon's section (1/2/D/1-7): Osei added him as a guest at
 * two of 1st Section's practices in the last two weeks (guests come from the host leader's hand for now, decided
 * 2026-10-23), and he attended his own section's practice every one of the last three weeks. One more visit, added by hand this week, makes him a JSAM candidate on 1st Section's roster. Refuses to run
 * outside local, testing and e2e: its user leads a section by command billet, so he holds real scope wherever he is
 * created.
 */
class GuestPracticeSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $now = CarbonImmutable::now('UTC');
        $week = WeekOf::start($now);
        $tag = $now->format('His').random_int(100, 999);

        // Its own path prefix (6), apart from the other seeders' (8, 9), so no unit of theirs sits under this company.
        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Delta Company', 'callsign' => 'D/1-7', 'path' => "6{$tag}/"]);
        $hostPlatoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/D/1-7', 'parent_id' => $company->id, 'path' => "6{$tag}/1/"]);
        $host = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/1/D/1-7', 'parent_id' => $hostPlatoon->id, 'path' => "6{$tag}/1/1/"]);
        $homePlatoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '2nd Platoon', 'callsign' => '2/D/1-7', 'parent_id' => $company->id, 'path' => "6{$tag}/2/"]);
        $home = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/2/D/1-7', 'parent_id' => $homePlatoon->id, 'path' => "6{$tag}/2/1/"]);

        $leader = $this->trooper('SGT', 'Osei.K', (int) "{$tag}0", $host);
        $user = User::factory()->create(['username' => 'Osei.K', 'cav_id' => $leader->cav_id]);
        UnitCommandMapping::factory()->create(['unit_id' => $host->id, 'cav_position_id' => (int) "{$tag}95"]);
        $leader->forceFill(['cav_position_id' => (int) "{$tag}95"])->save();
        $this->trooper('PFC', 'Lindgren.A', (int) "{$tag}1", $host);

        $guest = $this->trooper('SPC', 'Faraday.M', (int) "{$tag}2", $home);
        $practices = app(SectionPracticeRepository::class);

        foreach ([1, 2] as $back) {
            app(CrossSectionSightingRepository::class)->recordManual($practices->findOrCreate($host->id, $week->subWeeks($back)), $guest->id, $user->id);
        }

        foreach ([1, 2, 3] as $back) {
            app(AttendanceRepository::class)->markPractice($practices->findOrCreate($home->id, $week->subWeeks($back)), $guest->id, AttendanceStatus::Present, AttendanceSource::Manual);
        }
    }

    private function trooper(string $rank, string $username, int $cavId, Unit $section): Trooper
    {
        return Trooper::factory()->create([
            'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
        ]);
    }
}
