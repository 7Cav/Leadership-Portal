<?php

namespace Database\Seeders;

use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A platoon with three sections and six weeks of attendance and sent reports, for the report
 * compose screen's browser test (Stories 5.3–5.5), seeded by the e2e setup beside
 * `ReportRollupSeeder` under its own company and usernames (`Mercer.J` leads 2/1/B/1-7,
 * `Brennan.J` the platoon). Never seeded in production. Section 2 is left as this week's draft with open items; section 1 has sent this
 * week; section 3 has a draft. Exposes the users it made through `$sectionLeader` and
 * `$platoonLeader`.
 */
class ReportScreenSeeder extends Seeder
{
    public User $sectionLeader;

    public User $platoonLeader;

    public Unit $section;

    public Unit $platoon;

    public function run(): void
    {
        $now = CarbonImmutable::now('UTC');
        $week = WeekOf::reporting($now);
        $tag = $now->format('His').random_int(100, 999);

        $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'path' => "9{$tag}/"]);
        $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/B/1-7', 'parent_id' => $company->id, 'path' => "9{$tag}/1/"]);
        $first = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/1/B/1-7', 'parent_id' => $this->platoon->id, 'path' => "9{$tag}/1/1/"]);
        $this->section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '2nd Section', 'callsign' => '2/1/B/1-7', 'parent_id' => $this->platoon->id, 'path' => "9{$tag}/1/2/"]);
        $third = Unit::factory()->create(['type' => UnitType::Section, 'name' => '3rd Section', 'callsign' => '3/1/B/1-7', 'parent_id' => $this->platoon->id, 'path' => "9{$tag}/1/3/"]);

        $people = [
            ['SGT', 'Mercer.J'], ['CPL', 'Lindqvist.E'], ['SPC', 'Mbeki.K'], ['PFC', 'Okafor.T'],
            ['PFC', 'Kowalczyk.P'], ['PVT', 'Doyle.A'], ['PVT', 'Reyes.M'], ['PFC', 'Tanaka.H'],
        ];
        $troopers = collect($people)->map(function (array $person, int $i) use ($tag) {
            $cavId = (int) ($tag.$i);
            User::factory()->create(['username' => $person[1], 'cav_id' => $cavId]);

            return Trooper::factory()->create([
                'cav_id' => $cavId, 'rank_short' => $person[0], 'username' => $person[1], 'status' => TrooperStatus::Combat, 'primary_section_id' => $this->section->id,
            ]);
        });
        $this->sectionLeader = User::query()->where('cav_id', $troopers[0]->cav_id)->sole();
        PortalRoleGrant::factory()->create(['user_id' => $this->sectionLeader->id, 'unit_id' => $this->section->id]);

        $this->platoonLeader = User::factory()->serving()->create(['username' => 'Brennan.J']);
        PortalRoleGrant::factory()->create(['user_id' => $this->platoonLeader->id, 'unit_id' => $this->platoon->id]);

        foreach ([$first, $third] as $other) {
            Trooper::factory()->count(5)->create(['primary_section_id' => $other->id, 'rank_short' => 'PFC']);
        }

        // Six past weeks, each marked and sent by the section leader.
        $subjects = ['Radio procedure and SITREPs', 'Vehicle mount and dismount drills', 'Land navigation, map and compass', 'Fireteam formations, react to contact', 'CASEVAC drill and 9-line reports', 'Bounding overwatch in urban terrain'];
        foreach (range(6, 1) as $back) {
            $past = $week->subWeeks($back);
            foreach ($troopers as $i => $trooper) {
                $missed = ($i === 4 && $back <= 2) || ($i === 6 && in_array($back, [3, 4], true));
                $this->mark($this->section, $trooper, $past, $missed ? AttendanceStatus::Missed : AttendanceStatus::Present, $i === 5 && $back === 2 ? RollcallSignature::Unsigned : RollcallSignature::WillAttend);
            }
            $this->send($this->sectionLeader, $this->section, $past, $subjects[6 - $back], 'Good pace through the drills. Comms discipline improving.');
        }

        // This week: mostly marked, one trooper unmarked, one unaccounted miss, nothing written yet.
        $statuses = [AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Makeup, AttendanceStatus::Missed, null, AttendanceStatus::Attached, AttendanceStatus::Present];
        foreach ($troopers as $i => $trooper) {
            $this->mark($this->section, $trooper, $week, $statuses[$i], $i === 5 ? RollcallSignature::CannotAttend : RollcallSignature::WillAttend);
        }
        app(WeeklyReportRepository::class)->saveDraft($this->section->id, $week, [], 'Mbeki.K ran the CLS lane on his own for the first time.');

        // Section 1 has sent this week; section 3 is still a draft.
        foreach (Trooper::query()->where('primary_section_id', $first->id)->get() as $trooper) {
            $this->mark($first, $trooper, $week, AttendanceStatus::Present, RollcallSignature::WillAttend);
        }
        $this->send($this->platoonLeader, $first, $week, 'Breaching and room clearing', null);
        app(WeeklyReportRepository::class)->saveDraft($third->id, $week, [], null);
    }

    private function mark(Unit $section, Trooper $trooper, CarbonImmutable $week, ?AttendanceStatus $status, RollcallSignature $signature): void
    {
        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $week);
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $signature);
        if ($status !== null) {
            app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
        }
    }

    private function send(User $actor, Unit $unit, CarbonImmutable $week, ?string $subject, ?string $notes): void
    {
        app(SendWeeklyReportHandler::class)->handle($actor, new SendWeeklyReport($unit->id, $week, 'Weekly report', $notes, $subject));

        // Backdate a past week's freeze to its Friday evening, as it would have happened.
        if ($week->greaterThanOrEqualTo(WeekOf::reporting(CarbonImmutable::now('UTC')))) {
            return;
        }
        WeeklyReport::query()->where('unit_id', $unit->id)->where('week_start', $week)
            ->update(['sent_at' => $week->addDays(6)->setTime(21, 30)]);
    }
}
