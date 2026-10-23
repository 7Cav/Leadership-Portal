<?php

namespace Database\Seeders;

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Models\GamePlatform;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * A section whose practice this week was captured with three players no trooper is linked to, for the
 * unmatched-players drawer's browser test (Story 7.7), seeded by the e2e setup beside the other
 * seeders under its own company and usernames (`Hartmann.L` leads 3/2/C/1-7). One of them,
 * "Halvorsen", is meant for `Halvorsen.K`, who already holds another HLL ID: the replace-or-cancel
 * question (#176). A second section
 * (4/2/C/1-7, led by `Brandt.K`) holds a captured practice with two auto pre-marks and one trooper the
 * server did not see, for the review-and-confirm test (Story 7.8), plus `Mbatha.Z`, carrying an
 * automatic makeup written straight through the writer (the capture does not write makeups, 2026-10-23). A third (5/2/C/1-7, led by `Voss.H`)
 * holds a practice whose last capture failed, with an HLL-linked trooper so it can be retried, for the
 * telemetry-unavailable test (Story 7.9). Refuses to run outside local, testing and e2e: its users
 * lead their sections by command billet, so they hold real scope wherever they are created.
 */
class CaptureReviewSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $now = CarbonImmutable::now('UTC');
        $tag = $now->format('His').random_int(100, 999);

        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Charlie Company', 'callsign' => 'C/1-7', 'path' => "8{$tag}/"]);
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '2nd Platoon', 'callsign' => '2/C/1-7', 'parent_id' => $company->id, 'path' => "8{$tag}/1/"]);
        $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '3rd Section', 'callsign' => '3/2/C/1-7', 'parent_id' => $platoon->id, 'path' => "8{$tag}/1/1/"]);

        foreach ([['SGT', 'Hartmann.L'], ['PFC', 'Dube.R'], ['PVT', 'Nakamura.T'], ['SPC', 'Halvorsen.K']] as $i => [$rank, $username]) {
            $cavId = (int) ($tag.$i);
            User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
            $trooper = Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
            ]);

            // Already linked to another HLL account, so linking the captured "Halvorsen" to them asks to replace (#176).
            if ($username === 'Halvorsen.K') {
                TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'platform' => GamePlatform::Hll, 'player_name' => 'HalvoOld', 'platform_id' => '76561198000009099']);
            }
        }
        $this->leads('Hartmann.L', $section, (int) "{$tag}90");

        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        app(SectionPracticeRepository::class)->stampCapture($practice, 'crcon-e2e', $now, 'crcon');
        app(PracticeCapturePlayerRepository::class)->replaceForPractice($practice->id, GamePlatform::Hll, [
            ['platform_id' => '76561198000009001', 'player_name' => 'Dubes', 'trooper_id' => null],
            ['platform_id' => '76561198000009002', 'player_name' => 'RandoGuy88', 'trooper_id' => null],
            ['platform_id' => '76561198000009003', 'player_name' => 'Halvorsen', 'trooper_id' => null],
        ]);

        $this->seedReviewSection($platoon, $tag, $now, $practice);
        $this->seedDownSection($platoon, $tag, $now);
    }

    /**
     * Make the user the section's leader by billet: the roster-derived command, which writes (a staff
     * grant only reads).
     */
    private function leads(string $username, Unit $section, int $positionId): void
    {
        UnitCommandMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => $positionId]);
        Trooper::query()->where('cav_id', User::query()->where('username', $username)->sole()->cav_id)->sole()
            ->forceFill(['cav_position_id' => $positionId])->save();
    }

    private function seedReviewSection(Unit $platoon, string $tag, CarbonImmutable $now, SectionPractice $neighbour): void
    {
        $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '4th Section', 'callsign' => '4/2/C/1-7', 'parent_id' => $platoon->id, 'path' => "8{$tag}/1/2/"]);
        $ids = [];

        foreach ([['SGT', 'Brandt.K'], ['SPC', 'Okafor.D'], ['PFC', 'Lindqvist.E'], ['PVT', 'Reyes.J'], ['PFC', 'Mbatha.Z']] as $i => [$rank, $username]) {
            $cavId = (int) ($tag.'5'.$i);
            User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
            $ids[$username] = Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
            ])->id;
        }
        $this->leads('Brandt.K', $section, (int) "{$tag}91");

        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        app(MarkAttendanceHandler::class)->preMark($practice, [$ids['Okafor.D'], $ids['Lindqvist.E']]);
        app(SectionPracticeRepository::class)->stampCapture($practice, 'crcon-e2e-review', $now, 'crcon');
        // An automatic makeup on this section's rollcall, written directly: the capture does not write makeups (2026-10-23).
        app(MarkAttendanceHandler::class)->preMarkMakeups($neighbour, [$ids['Mbatha.Z']]);
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
        $this->leads('Voss.H', $section, (int) "{$tag}92");

        // The practice was read earlier and no source answered: no stamp, no marks, a failed run naming it.
        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, WeekOf::start($now));
        SyncRun::factory()->create([
            'source' => 'capture', 'status' => SyncRunStatus::Failure, 'started_at' => $now->subHours(3), 'finished_at' => $now->subHours(3),
            'section_practice_id' => $practice->id, 'error' => 'telemetry unavailable (practice '.$practice->id.'): crcon: unreachable',
        ]);
    }
}
