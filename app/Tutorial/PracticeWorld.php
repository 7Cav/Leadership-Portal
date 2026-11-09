<?php

namespace App\Tutorial;

use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TutorialPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Fills a fresh practice copy with the tutorial's fixed practice unit: a made-up battalion (9-7) down to two sections,
 * made-up troopers, four weeks of history, a leave, and this week's practice already seen on a practice game server.
 * The person themselves is placed in it at the echelon they hold for real ({@see TutorialPersona}).
 *
 * Every name is made up and obviously so, except two: Laui.M and Grayson.J, the portal's maintainers, who serve in
 * every practice unit as ordinary troopers (approved by the project). Nothing here is read from real troopers.
 *
 * Only runs inside the sandbox: it writes to whatever the default connection is.
 */
final class PracticeWorld
{
    public const REGIMENT = '9CAV';

    public const BATTALION = '9-7';

    public const COMPANY = 'A/9-7';

    public const PLATOON = '1/A/9-7';

    public const SECTION = '1/1/A/9-7';

    public const OTHER_SECTION = '2/1/A/9-7';

    /** The trooper whose signature and mark the roll call step asks for. */
    public const DEMO = 'Demo.K';

    /** The two maintainers in every practice unit. */
    public const MAINTAINERS = ['Laui.M', 'Grayson.J'];

    private const HISTORY_WEEKS = 4;

    private const SUBJECTS = ['Radio procedure', 'Bounding overwatch', 'Land navigation', 'React to contact'];

    private int $nextCavId = 91_000_001;

    public function __construct(
        private readonly TutorialSandbox $sandbox,
        private readonly TutorialPracticeRepository $practice,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly SendWeeklyReportHandler $send,
    ) {}

    public function build(int $userId, TutorialPersona $persona, ?CarbonImmutable $now = null): void
    {
        if ($this->sandbox->userId() !== $userId) {
            throw new LogicException('The practice unit is only built inside the person\'s own tutorial sandbox.');
        }

        $now ??= CarbonImmutable::now('UTC');
        $week = WeekOf::reporting($now);
        $since = $week->subWeeks(12);

        $me = $this->practice->copyUser($userId, $persona->forumUserId, $persona->cavId ?? 91_999_999, $persona->username, $persona->admin);

        $regiment = $this->practice->unit(null, UnitType::Regiment, 'Practice Regiment', self::REGIMENT);
        $battalion = $this->practice->unit($regiment, UnitType::Battalion, '9th Battalion', self::BATTALION);
        $company = $this->practice->unit($battalion, UnitType::Company, 'Alpha Company', self::COMPANY);
        $platoon = $this->practice->unit($company, UnitType::Platoon, '1st Platoon', self::PLATOON);
        $section = $this->practice->unit($platoon, UnitType::Section, '1st Section', self::SECTION);
        $other = $this->practice->unit($platoon, UnitType::Section, '2nd Section', self::OTHER_SECTION);
        $game = $this->practice->game('Practice game', 'practice-game', 'PRAC');
        $this->practice->setUnitGame($battalion, $game);

        $byType = [
            UnitType::Battalion->value => $battalion,
            UnitType::Company->value => $company,
            UnitType::Platoon->value => $platoon,
            UnitType::Section->value => $section,
        ];
        $leads = $persona->leads === null ? null : $byType[$persona->leads->value] ?? null;
        $placedOn = $leads ?? ($persona->hqStaff === null ? $section : ($byType[$persona->hqStaff->value] ?? $section));

        $self = $this->practice->trooper(
            (int) $me->cav_id, $persona->forumUserId, $persona->rankShort, $persona->rank, $persona->username,
            $leads?->billet() ?? 'Rifleman', $placedOn, $since, $leads,
        );

        // The chain above and beside the person: each leader holds their unit unless the person leads it.
        $people = [
            [$battalion, 'MAJ', 'Major', 'Rehearsal.M', 'Battalion Commander', true],
            [$company, 'CPT', 'Captain', 'Testcase.C', 'Company Commander', true],
            [$platoon, '2LT', 'Second Lieutenant', 'Placeholder.P', 'Platoon Leader', true],
            [$platoon, 'SFC', 'Sergeant First Class', 'Draft.W', 'Platoon Sergeant', false],
            [$section, 'SGT', 'Sergeant', 'Practice.L', 'Section Leader', true],
            [$section, 'CPL', 'Corporal', 'Sample.R', 'Team Leader', false],
            [$section, 'SPC', 'Specialist', self::DEMO, 'Rifleman', false],
            [$section, 'PFC', 'Private First Class', 'Mock.T', 'Grenadier', false],
            [$section, 'PVT', 'Private', 'Trial.B', 'Rifleman', false],
            [$section, 'PFC', 'Private First Class', 'Example.N', 'Combat Medic', false],
            [$section, 'SPC', 'Specialist', 'Laui.M', 'Automatic Rifleman', false],
            [$other, 'SGT', 'Sergeant', 'Specimen.D', 'Section Leader', true],
            [$other, 'CPL', 'Corporal', 'Grayson.J', 'Team Leader', false],
            [$other, 'PFC', 'Private First Class', 'Model.G', 'Rifleman', false],
            [$other, 'PVT', 'Private', 'Pilot.H', 'Grenadier', false],
            [$other, 'PFC', 'Private First Class', 'Dummy.F', 'Combat Medic', false],
        ];

        $troopers = ['self' => $self];
        foreach ($people as [$unit, $rankShort, $rank, $name, $billet, $commands]) {
            $holds = $commands && $leads?->id !== $unit->id;
            $troopers[$name] = $this->practice->trooper($this->nextCavId++, null, $rankShort, $rank, $name, $holds ? $unit->billet() : $billet, $unit, $since, $holds ? $unit : null);
        }

        // Past reports go out under the section's leader: the person, when they lead the section themselves.
        $sectionLead = $leads?->id === $section->id ? $me : $this->practice->practiceUser(91_900_001, (int) $troopers['Practice.L']->cav_id, 'Practice.L');
        $otherLead = $this->practice->practiceUser(91_900_002, (int) $troopers['Specimen.D']->cav_id, 'Specimen.D');

        $this->practice->loa($troopers['Mock.T'], $week->subDays(2), $week->addDays(12));

        // This week's practice hour: earlier today if the week allows, so it has already happened.
        $start = $now->subHours(3)->startOfHour();
        $start = $start->lessThan($week) ? $week : $start;
        $this->practice->slot($section, (int) $start->dayOfWeek, $start->hour * 60, $since);
        $this->practice->slot($other, (int) $start->dayOfWeek, $start->hour * 60, $since);

        $sectionMembers = array_values(array_filter($troopers, fn (Trooper $t) => (int) $t->primary_section_id === $section->id));
        $otherMembers = array_values(array_filter($troopers, fn (Trooper $t) => (int) $t->primary_section_id === $other->id));

        foreach (range(self::HISTORY_WEEKS, 1) as $back) {
            $past = $week->subWeeks($back);
            $this->history($section, $sectionMembers, $past, $sectionLead, self::SUBJECTS[$back - 1], $back);
            $this->history($other, $otherMembers, $past, $otherLead, self::SUBJECTS[(self::HISTORY_WEEKS - $back)], $back + 1);
        }

        // This week: seen on the practice server, not confirmed, not posted, and Demo.K neither signed nor seen.
        $practice = $this->practices->findOrCreate($section->id, $week);
        $this->practice->captured($practice, $start->addHour()->min($now), $start, $start->addHour());
        foreach ($sectionMembers as $trooper) {
            if ($trooper->username === self::DEMO) {
                $this->attendance->signRollcall($practice, $trooper->id, RollcallSignature::Unsigned);

                continue;
            }
            $this->attendance->signRollcall($practice, $trooper->id, RollcallSignature::WillAttend);
            if ($trooper->username !== 'Mock.T') {
                $this->attendance->markPracticeAuto($practice, $trooper->id);
                $this->practice->capturePlayer($practice, $game, $trooper->username, 'practice-'.$trooper->cav_id, $trooper);
            }
        }
        $this->practice->capturePlayer($practice, $game, 'Unknown.Visitor', 'practice-visitor', null);

        // A leader above the sections sees one section already in for the week.
        if ($persona->leads !== null && $persona->leads !== UnitType::Section) {
            $this->history($other, $otherMembers, $week, $otherLead, 'Fireteam formations', 0);
        }
    }

    /** @param  list<Trooper>  $members */
    private function history(Unit $section, array $members, CarbonImmutable $week, User $lead, string $subject, int $seed): void
    {
        $practice = $this->practices->findOrCreate($section->id, $week);
        foreach ($members as $i => $trooper) {
            $this->attendance->signRollcall($practice, $trooper->id, RollcallSignature::WillAttend);
            $missed = ($i + $seed) % 5 === 0;
            $this->attendance->markPractice($practice, $trooper->id, $missed ? AttendanceStatus::Missed : AttendanceStatus::Present, AttendanceSource::Manual);
        }
        $this->practices->confirm($practice, $lead->id);
        $this->send->handle($lead, new SendWeeklyReport($section->id, $week, 'Weekly report', 'Good drills, comms improving.', $subject));
    }
}
