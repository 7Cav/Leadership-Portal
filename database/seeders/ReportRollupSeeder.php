<?php

namespace Database\Seeders;

use App\Kpi\WeeklyReportKpis;
use App\Models\ArtifactType;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * A company's reporting week for the report roll-up workspace (Story 5.8): the browser test and
 * screenshots run against it. Builds 7CAV ─ 1-7 ─ A/1-7 with two platoons of four sections, four
 * weeks of attendance, last week sent everywhere, and this week five of eight sections sent, two
 * drafts and one not started. `Hale.S` holds a staff grant over the company; `Vasquez.R` over one
 * section. Run against an empty database only (the e2e setup migrates fresh first). Refuses to run
 * outside local, testing and e2e: those two users hold real scope wherever they are created.
 */
class ReportRollupSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    /** Section callsign => [leader, troopers, this week's state, notes]. */
    private const SECTIONS = [
        '1/1/A/1-7' => ['Haddad.Y', 6, 'sent', 'Full house for the AT drill.'],
        '2/1/A/1-7' => ['Vasquez.R', 8, 'draft', 'Convoy drill went well. One no-show, 1:1 set for Friday.'],
        '3/1/A/1-7' => ['Ferreira.D', 5, 'sent', 'One unsigned and missed, flagged to the platoon leader.'],
        '4/1/A/1-7' => ['Brandt.O', 5, 'sent', 'Rollcall went up Monday, my fault.'],
        '1/2/A/1-7' => ['Nakashima.R', 6, 'sent', 'Nothing to flag.'],
        '2/2/A/1-7' => ['Oduya.C', 6, 'none', null],
        '3/2/A/1-7' => ['Sorensen.B', 5, 'draft', 'Two still unsigned.'],
        '4/2/A/1-7' => ['Fournier.L', 6, 'sent', 'New trooper joined from basic.'],
    ];

    private const ROLLCALL_TEMPLATE = <<<'BBCODE'
[b]{{unit}} Rollcall, week of {{week_start}}[/b]

[table]
[tr][td][b]Rank[/b][/td][td][b]Name[/b][/td][td][b]Signed[/b][/td][td][b]SP[/b][/td][/tr]
{{#troopers}}[tr][td]{{rank_short}}[/td][td]{{name}}[/td][td]{{rc}}[/td][td]{{sp}}[/td][/tr]
{{/troopers}}[/table]
BBCODE;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $units = app(UnitRepository::class);
        $reports = app(WeeklyReportRepository::class);
        $snapshots = app(WeeklyReportKpis::class);

        $week = WeekOf::reporting(CarbonImmutable::now());

        $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
        $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
        $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
        $platoons = [
            1 => $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id),
            2 => $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id),
        ];

        // Serving, or their grants would lapse (a grant counts only while its holder serves).
        $commander = User::factory()->serving()->create(['username' => 'Hale.S']);
        PortalRoleGrant::factory()->create(['user_id' => $commander->id, 'unit_id' => $company->id]);

        // The company's rollcall template (Story 3.11): every section under it renders its forum post from it, and an
        // Admin (`Pike.S`) edits it from Settings. A company without one shows no post.
        $admin = User::factory()->serving()->admin()->create(['username' => 'Pike.S']);
        app(TemplateVersionRepository::class)->author($company->id, ArtifactType::RollcallBbcode, self::ROLLCALL_TEMPLATE, $admin->id);

        $sections = [];
        foreach (self::SECTIONS as $callsign => [$leader, $strength, $state, $notes]) {
            [$number, $platoon] = explode('/', $callsign);
            $ordinal = ['1' => '1st', '2' => '2nd', '3' => '3rd', '4' => '4th'][$number];
            $section = $units->create(UnitType::Section, "{$ordinal} Section", $callsign, $platoons[(int) $platoon]->id);
            $user = User::factory()->serving()->create(['username' => $leader]);
            PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $section->id]);
            $this->attendance($section, $strength, $week);
            $sections[] = [$section, $user, $state, $notes];
        }

        // Last week: every section sent, so this week's numbers carry a delta.
        foreach ($sections as [$section, $user]) {
            $this->send($reports, $snapshots, $section, $week->subWeek(), $user, null);
        }

        foreach ($sections as [$section, $user, $state, $notes]) {
            match ($state) {
                'sent' => $this->send($reports, $snapshots, $section, $week, $user, $notes),
                'draft' => $reports->saveDraft($section->id, $week, $snapshots->snapshot($section, $week)->toArray(), $notes),
                default => null,
            };
        }
    }

    /** Four weeks of rollcall signatures and practice marks, varied by trooper and week. */
    private function attendance(Unit $section, int $strength, CarbonImmutable $week): void
    {
        $practices = app(SectionPracticeRepository::class);
        $attendance = app(AttendanceRepository::class);

        for ($t = 0; $t < $strength; $t++) {
            $trooper = Trooper::factory()->create();
            $trooper->forceFill(['primary_section_id' => $section->id])->save();

            for ($back = 0; $back < 4; $back++) {
                $practice = $practices->findOrCreate($section->id, $week->subWeeks($back));
                $roll = ($t + $back + $section->id) % 5;
                $status = match (($t * 3 + $back + $section->id) % 7) {
                    0 => AttendanceStatus::Missed,
                    1 => AttendanceStatus::Makeup,
                    default => AttendanceStatus::Present,
                };
                $attendance->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
                if ($roll !== 0) {
                    $attendance->signRollcall($practice, $trooper->id, $roll === 1 ? RollcallSignature::CannotAttend : RollcallSignature::WillAttend);
                }
            }
        }
    }

    private function send(WeeklyReportRepository $reports, WeeklyReportKpis $snapshots, Unit $section, CarbonImmutable $week, User $by, ?string $notes): void
    {
        $snapshot = $snapshots->snapshot($section, $week)->toArray();
        $reports->send($section->id, $week, $snapshot, $notes, $by->id, hash('sha256', json_encode($snapshot)), null);
    }
}
