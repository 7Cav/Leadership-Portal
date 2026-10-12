<?php

namespace Database\Seeders;

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\GamePlatform;
use App\Models\PortalRoleGrant;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A section whose practice this week was captured with two players no trooper is linked to, for the
 * unmatched-players drawer's browser test (Story 7.7), seeded by the e2e setup beside the other
 * seeders under its own company and usernames (`Hartmann.L` leads 3/2/C/1-7). A second section
 * (4/2/C/1-7, led by `Brandt.K`) holds a captured practice with two auto pre-marks and one trooper the
 * server did not see, for the review-and-confirm test (Story 7.8). A third (5/2/C/1-7, led by `Voss.H`)
 * holds a practice whose last capture failed, with an HLL-linked trooper so it can be retried, for the
 * telemetry-unavailable test (Story 7.9). Never seeded in production.
 */
class CaptureReviewSeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::now('UTC');
        $tag = $now->format('His').random_int(100, 999);

        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Charlie Company', 'callsign' => 'C/1-7', 'path' => "8{$tag}/"]);
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '2nd Platoon', 'callsign' => '2/C/1-7', 'parent_id' => $company->id, 'path' => "8{$tag}/1/"]);
        $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '3rd Section', 'callsign' => '3/2/C/1-7', 'parent_id' => $platoon->id, 'path' => "8{$tag}/1/1/"]);

        foreach ([['SGT', 'Hartmann.L'], ['PFC', 'Dube.R'], ['PVT', 'Nakamura.T'], ['SPC', 'Halvorsen.K']] as $i => [$rank, $username]) {
            $cavId = (int) ($tag.$i);
            User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
            Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
            ]);
        }
        PortalRoleGrant::factory()->create(['user_id' => User::query()->where('username', 'Hartmann.L')->sole()->id, 'unit_id' => $section->id]);

        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        app(SectionPracticeRepository::class)->stampCapture($practice, 'crcon-e2e', $now, 'crcon');
        app(PracticeCapturePlayerRepository::class)->replaceForPractice($practice->id, GamePlatform::Hll, [
            ['platform_id' => '76561198000009001', 'player_name' => 'Dubes', 'trooper_id' => null],
            ['platform_id' => '76561198000009002', 'player_name' => 'RandoGuy88', 'trooper_id' => null],
        ]);

        $this->seedReviewSection($platoon, $tag, $now);
        $this->seedDownSection($platoon, $tag, $now);
    }

    private function seedReviewSection(Unit $platoon, string $tag, CarbonImmutable $now): void
    {
        $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '4th Section', 'callsign' => '4/2/C/1-7', 'parent_id' => $platoon->id, 'path' => "8{$tag}/1/2/"]);
        $ids = [];

        foreach ([['SGT', 'Brandt.K'], ['SPC', 'Okafor.D'], ['PFC', 'Lindqvist.E'], ['PVT', 'Reyes.J']] as $i => [$rank, $username]) {
            $cavId = (int) ($tag.'5'.$i);
            User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
            $ids[$username] = Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
            ])->id;
        }
        PortalRoleGrant::factory()->create(['user_id' => User::query()->where('username', 'Brandt.K')->sole()->id, 'unit_id' => $section->id]);

        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        app(MarkAttendanceHandler::class)->preMark($practice, [$ids['Okafor.D'], $ids['Lindqvist.E']]);
        app(SectionPracticeRepository::class)->stampCapture($practice, 'crcon-e2e-review', $now, 'crcon');
    }

    private function seedDownSection(Unit $platoon, string $tag, CarbonImmutable $now): void
    {
        $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '5th Section', 'callsign' => '5/2/C/1-7', 'parent_id' => $platoon->id, 'path' => "8{$tag}/1/3/"]);

        foreach ([['SGT', 'Voss.H'], ['PFC', 'Park.J'], ['PVT', 'Mbeki.S']] as $i => [$rank, $username]) {
            $cavId = (int) ($tag.'6'.$i);
            User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
            $trooper = Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
            ]);

            if ($username === 'Park.J') {
                TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'platform' => GamePlatform::Hll, 'player_name' => 'Park', 'platform_id' => '76561198000009101']);
            }
        }
        PortalRoleGrant::factory()->create(['user_id' => User::query()->where('username', 'Voss.H')->sole()->id, 'unit_id' => $section->id]);

        // The practice was read earlier and no source answered: no stamp, no marks, a failed run naming it.
        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        SyncRun::factory()->create([
            'source' => 'capture', 'status' => SyncRunStatus::Failure, 'started_at' => $now->subHours(3), 'finished_at' => $now->subHours(3),
            'section_practice_id' => $practice->id, 'error' => 'telemetry unavailable (practice '.$practice->id.'): crcon: unreachable',
        ]);
    }
}
