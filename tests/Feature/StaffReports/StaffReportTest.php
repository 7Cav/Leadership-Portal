<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffPractices\RemoveStaffPractice;
use App\Commands\StaffPractices\RemoveStaffPracticeHandler;
use App\Commands\StaffPractices\StaffPracticeRefusedException;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportForbiddenException;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Kpi\KpiService;
use App\Kpi\PracticeGuests;
use App\Milpac\StaffReportWeek;
use App\Models\Attendance;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\PlacementSource;
use App\Models\SectionPractice;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallGuests;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../StaffPractices/fixture.php';

// Each platoon or company HQ staff member files their own weekly staff report, saying whether they
// attended a section practice that week and which. Each practice picked lists them on the host's rollcall, as a staff
// guest in their own platoon or company and as a guest elsewhere, shown only.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    // The Saturday after the fixture's week: it has ended and its report is due tonight.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);
    // A second platoon in the same company: outside the PSG's platoon, inside the CO's company.
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->tree['units']['company']->id);
    $this->far = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $this->far->id]);
    Trooper::factory()->create(['primary_section_id' => $this->tree['units']['other']->id]);
    $this->host = $this->tree['units']['host'];
    $this->psg = $this->tree['troopers']['psg'];
});

function sendStaffReport(User $actor, Trooper $trooper, bool $attended, array $sectionIds, ?CarbonImmutable $week = null): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($actor, new SendStaffReport($trooper->id, $week ?? staffPracticeWeek(), $attended, $sectionIds));
}

function staffReportRefusal(Closure $write): string
{
    try {
        $write();
    } catch (StaffReportRefusedException $e) {
        return $e->reason;
    }

    throw new RuntimeException('Expected the staff report to be refused.');
}

/** The trooper behind one of the fixture's commanding users. */
function trooperOf(User $user): Trooper
{
    return Trooper::query()->where('cav_id', $user->cav_id)->sole();
}

it('lets a platoon HQ staff member send their own report, keeping each practice as their staff entry, audited', function () {
    $report = sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id, $this->far->id]);

    expect($report->attended)->toBeTrue()
        ->and($report->unit_id)->toBe($this->tree['units']['platoon']->id)
        ->and($report->week_start->toDateString())->toBe('2026-10-03')
        ->and($report->sent_by)->toBe($this->tree['users']['psg']->id)
        // MySQL keeps JSON object keys in its own order, so the keys are compared without their order.
        ->and($report->practices)->toEqual([
            ['section_id' => $this->host->id, 'section' => '1/1/A/1-7'],
            ['section_id' => $this->far->id, 'section' => '1/2/A/1-7'],
        ]);

    $rows = CrossSectionSighting::query()->orderBy('id')->get();
    expect($rows->pluck('source')->unique()->all())->toBe([CrossSectionSighting::SOURCE_STAFF])
        ->and($rows->pluck('trooper_id')->unique()->all())->toBe([$this->psg->id])
        ->and($rows->pluck('recorded_by')->unique()->all())->toBe([$this->tree['users']['psg']->id]);

    $audit = AuditLogEntry::query()->where('action', 'staff_report.sent')->sole();
    expect($audit->actor_id)->toBe($this->tree['users']['psg']->id)
        ->and($audit->target_type)->toBe('StaffReport')
        ->and((int) $audit->target_id)->toBe($report->id);
});

it('lets a company HQ staff member send theirs too', function () {
    $co = trooperOf($this->tree['users']['co']);

    expect(sendStaffReport($this->tree['users']['co'], $co, true, [$this->far->id])->unit_id)->toBe($this->tree['units']['company']->id);
});

it('records "No" as a sent report with no practice, and takes off the week\'s entries it did not pick', function () {
    // The PL entered one for their PSG on the milpac; the PSG's own report says they did not attend.
    app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->host->id, $this->week));

    $report = sendStaffReport($this->tree['users']['psg'], $this->psg, false, []);

    expect($report->attended)->toBeFalse()
        ->and($report->practices)->toBe([])
        ->and(CrossSectionSighting::query()->count())->toBe(0)
        // The practice the milpac entry made is gone with it, as removing that entry would have done.
        ->and(SectionPractice::query()->count())->toBe(0);
});

it('audits each entry the send takes off, naming the entry and who had entered it', function () {
    $entry = app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->host->id, $this->week));

    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->far->id]);

    $removed = AuditLogEntry::query()->where('action', 'staff_practice.removed')->sole();
    expect($removed->actor_id)->toBe($this->tree['users']['psg']->id)
        ->and($removed->target_type)->toBe('CrossSectionSighting')
        ->and((int) $removed->target_id)->toBe($entry->id)
        ->and($removed->reason)->toContain('1/1/A/1-7')
        ->and($removed->reason)->toContain($this->tree['users']['pl']->username)
        ->and(AuditLogEntry::query()->where('action', 'staff_report.sent')->count())->toBe(1);
});

it('keeps an entry already there for a section it picked, and drops one it did not', function () {
    $record = app(RecordStaffPracticeHandler::class);
    $kept = $record->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->host->id, $this->week));
    $record->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->tree['units']['other']->id, $this->week));

    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(CrossSectionSighting::query()->sole()->id)->toBe($kept->id);
});

it('refuses "Yes" with nothing picked, "No" with picks, and a pick that is not a section', function (bool $attended, Closure $ids, string $reason) {
    expect(staffReportRefusal(fn () => sendStaffReport($this->tree['users']['psg'], $this->psg, $attended, $ids($this))))->toBe($reason);
    expect(StaffReport::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'yes, nothing picked' => [true, fn () => [], 'no_practice'],
    'no, with a pick' => [false, fn ($t) => [$t->host->id], 'no_practice'],
    'a platoon picked' => [true, fn ($t) => [$t->tree['units']['platoon']->id], 'not_a_section'],
    'a section that does not exist' => [true, fn () => [999999], 'not_a_section'],
]);

it('refuses anyone but the staff member themselves, writing nothing', function (string $actor) {
    expect(fn () => sendStaffReport($this->tree['users'][$actor], $this->psg, true, [$this->host->id]))
        ->toThrow(StaffReportForbiddenException::class);

    expect(StaffReport::query()->count())->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'their platoon leader' => ['pl'],
    'the company commander' => ['co'],
    'an Admin' => ['admin'],
    'a staff grant on the company' => ['grant'],
    'another staff member' => ['s6Member'],
]);

it('refuses an Admin acting as the staff member: acting as them, it is not their own report', function () {
    $this->actingAs($this->tree['users']['admin'])->post('/act-as', ['kind' => 'user', 'id' => $this->tree['users']['psg']->id])->assertRedirect('/home');

    $this->actingAs($this->tree['users']['admin'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['attended' => true, 'section_ids' => [$this->host->id]])
        ->assertNotFound();

    expect(StaffReport::query()->count())->toBe(0);
});

it('refuses a send during view as, before it reaches the Command', function () {
    $pl = $this->tree['users']['pl'];
    app(ViewAs::class)->enter($pl, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['platoon']->id));

    $this->actingAs($pl)
        ->post(route('staff-report.send', ['trooper' => trooperOf($pl)->id, 'week' => '2026-10-03']), ['attended' => false, 'section_ids' => []])
        ->assertForbidden();

    expect(StaffReport::query()->count())->toBe(0);
});

it('refuses a trooper who files no staff report: a section trooper, a staff department, regiment HQ, ELOA', function (Closure $who) {
    [$user, $trooper] = $who($this);

    expect(staffReportRefusal(fn () => sendStaffReport($user, $trooper, false, [])))->toBe('not_owed');
})->with([
    'a section trooper' => [fn ($t) => [$t->tree['users']['sl'], trooperOf($t->tree['users']['sl'])]],
    'a staff department member' => [fn ($t) => [$t->tree['users']['s6Member'], $t->tree['troopers']['s6Member']]],
    'regiment HQ staff' => [function ($t) {
        $trooper = Trooper::factory()->create(['primary_section_id' => $t->tree['units']['regiment']->id]);

        return [User::factory()->create(['cav_id' => $trooper->cav_id]), $trooper];
    }],
    'ELOA' => [function ($t) {
        $trooper = $t->tree['troopers']['eloa'];

        return [User::factory()->create(['cav_id' => $trooper->cav_id]), $trooper];
    }],
]);

it('refuses a week before the floor, a future week and the running week', function (string $week, string $reason) {
    expect(staffReportRefusal(fn () => sendStaffReport($this->tree['users']['psg'], $this->psg, false, [], CarbonImmutable::parse($week, 'UTC'))))->toBe($reason);
})->with([
    'before the floor' => ['2026-05-02', 'before_floor'],
    'the running week' => ['2026-10-10', 'not_ended'],
    'next week' => ['2026-10-17', 'future'],
]);

it('is final once sent: no second send, and the week\'s staff entries no longer change on the milpac', function () {
    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    expect(staffReportRefusal(fn () => sendStaffReport($this->tree['users']['psg'], $this->psg, false, [])))->toBe('already_sent');

    foreach ([
        fn () => app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->tree['units']['other']->id, $this->week)),
        fn () => app(RemoveStaffPracticeHandler::class)->handle($this->tree['users']['psg'], new RemoveStaffPractice($this->psg->id, $this->host->id, $this->week)),
    ] as $write) {
        try {
            $write();
            $this->fail('Expected the entry to be refused.');
        } catch (StaffPracticeRefusedException $e) {
            expect($e->reason)->toBe('locked')
                ->and($e->getMessage())->toBe("{$this->psg->display_name} sent their staff report for the week ending 09Oct26, so that week can no longer change.");
        }
    }

    expect(StaffReport::query()->count())->toBe(1)
        ->and(CrossSectionSighting::query()->count())->toBe(1);
});

it('is not locked by the HQ\'s own weekly report: the staff member still sends after their platoon did', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->tree['units']['platoon']->id, 'week_start' => $this->week]);

    expect(sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id])->attended)->toBeTrue();
});

it('goes by who was on the HQ when a past week ended, not by who is there now', function () {
    $placements = app(TrooperPlacementRepository::class);
    $lastWeek = CarbonImmutable::parse('2026-09-26 00:00:00', 'UTC');
    // The PSG moved into a section on Sunday of this week; the rifleman moved onto the platoon HQ the same day.
    $rifleman = $this->tree['troopers']['rifleman'];
    $riflemanUser = User::factory()->create(['cav_id' => $rifleman->cav_id]);
    $placements->record([
        $this->psg->id => [$this->tree['units']['platoon']->id, $this->tree['units']['other']->id],
        $rifleman->id => [$this->host->id, $this->tree['units']['platoon']->id],
    ], PlacementSource::Sync, CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    $this->psg->forceFill(['primary_section_id' => $this->tree['units']['other']->id])->save();
    $rifleman->forceFill(['primary_section_id' => $this->tree['units']['platoon']->id])->save();

    // Last week the PSG was on the HQ when it ended, so it is theirs to report; this week it is not.
    expect(sendStaffReport($this->tree['users']['psg'], $this->psg->refresh(), true, [$this->host->id], $lastWeek)->unit_id)->toBe($this->tree['units']['platoon']->id)
        ->and(staffReportRefusal(fn () => sendStaffReport($this->tree['users']['psg'], $this->psg, false, [])))->toBe('not_owed');

    // The rifleman owes this week's, not last week's.
    expect(staffReportRefusal(fn () => sendStaffReport($riflemanUser, $rifleman->refresh(), false, [], $lastWeek)))->toBe('not_owed')
        ->and(sendStaffReport($riflemanUser, $rifleman, false, [])->attended)->toBeFalse();
});

it('reads a trooper whose placement history began after the week ended as placed where they are now', function () {
    // The PL's only history row starts after the week ended (the history began after it): it says nothing about it.
    $pl = trooperOf($this->tree['users']['pl']);
    TrooperPlacement::query()->where('trooper_id', $pl->id)->delete();
    TrooperPlacement::query()->create(['trooper_id' => $pl->id, 'unit_id' => $this->tree['units']['platoon']->id, 'placed_from' => '2026-10-10 06:00:00', 'placed_until' => null, 'source' => PlacementSource::Sync->value]);

    expect(sendStaffReport($this->tree['users']['pl'], $pl, false, [])->unit_id)->toBe($this->tree['units']['platoon']->id)
        ->and(collect(app(StaffReportWeek::class)->hqStaff($this->tree['units']['platoon'], $this->week))->map(fn (array $row) => $row['trooper']->id)->all())->toContain($pl->id);
});

it('still goes by the history when it shows the trooper elsewhere when the week ended', function () {
    // On a section until after the week ended, then on the platoon HQ: the week was not theirs to report.
    $rifleman = $this->tree['troopers']['rifleman'];
    $riflemanUser = User::factory()->create(['cav_id' => $rifleman->cav_id]);
    TrooperPlacement::query()->where('trooper_id', $rifleman->id)->delete();
    TrooperPlacement::query()->create(['trooper_id' => $rifleman->id, 'unit_id' => $this->host->id, 'placed_from' => null, 'placed_until' => '2026-10-10 06:00:00', 'source' => PlacementSource::Sync->value]);
    TrooperPlacement::query()->create(['trooper_id' => $rifleman->id, 'unit_id' => $this->tree['units']['platoon']->id, 'placed_from' => '2026-10-10 06:00:00', 'placed_until' => null, 'source' => PlacementSource::Sync->value]);
    $rifleman->forceFill(['primary_section_id' => $this->tree['units']['platoon']->id])->save();

    expect(staffReportRefusal(fn () => sendStaffReport($riflemanUser, $rifleman->refresh(), false, [])))->toBe('not_owed');
});

it('keeps on a past week\'s HQ staff those who served when it ended or sent their report, though gone since', function () {
    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);
    $pl = trooperOf($this->tree['users']['pl']);
    $staff = fn () => collect(app(StaffReportWeek::class)->hqStaff($this->tree['units']['platoon'], $this->week))
        ->map(fn (array $row) => $row['trooper']->id)->sort()->values()->all();

    // Both left after the week ended: the PSG sent theirs, the PL served to the end of it.
    $this->psg->forceFill(['status' => TrooperStatus::Inactive, 'departed_at' => '2026-10-20 12:00:00'])->save();
    $pl->forceFill(['status' => TrooperStatus::Inactive, 'departed_at' => '2026-10-12 12:00:00'])->save();
    expect($staff())->toBe(collect([$this->psg->id, $pl->id])->sort()->values()->all());

    // The PL left before the week ended and sent nothing; the PSG's sent report keeps them.
    $pl->forceFill(['departed_at' => '2026-10-09 12:00:00'])->save();
    $this->psg->forceFill(['departed_at' => '2026-10-04 12:00:00'])->save();
    expect($staff())->toBe([$this->psg->id]);
});

it('reads the staff guests\' HQs in the same number of queries for one staff guest and several', function () {
    $record = app(RecordStaffPracticeHandler::class);
    $count = function (): int {
        $practice = app(SectionPracticeRepository::class)->find($this->host->id, $this->week);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RollcallGuests::class)->forPractice($practice);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $record->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->host->id, $this->week));
    $one = $count();

    foreach (range(1, 3) as $n) {
        $staff = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['platoon']->id]);
        $record->handle($this->tree['users']['pl'], new RecordStaffPractice($staff->id, $this->host->id, $this->week));
    }

    expect(app(RollcallGuests::class)->forPractice(app(SectionPracticeRepository::class)->find($this->host->id, $this->week)))->toHaveCount(4)
        ->and($count())->toBe($one);
});

it('lists each pick on the host rollcall: a staff guest in their own unit, a guest elsewhere', function () {
    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id, $this->far->id]);
    $co = trooperOf($this->tree['users']['co']);
    sendStaffReport($this->tree['users']['co'], $co, true, [$this->far->id]);

    $guests = fn ($section) => collect(app(RollcallGuests::class)->forPractice(app(SectionPracticeRepository::class)->find($section->id, $this->week)))
        ->mapWithKeys(fn (array $row) => [$row['trooperId'] => [$row['source'], $row['staffGuest']]])
        ->all();

    // The PSG's platoon holds the host: staff guest. 1/2/A/1-7 is outside their platoon: guest. It is inside the CO's company.
    expect($guests($this->host))->toBe([$this->psg->id => ['staff', true]])
        ->and($guests($this->far))->toEqual([$this->psg->id => ['staff', false], $co->id => ['staff', true]]);

    // The rollcall page names it, and the week is named, since a bare URL on a Saturday opens the week still due.
    $page = $this->actingAs($this->tree['users']['sl'])->get(route('rollcall.show', ['unit' => $this->host->id, 'week' => '2026-10-03']))->assertOk()->viewData('page')['props'];
    expect($page['guests'])->toHaveCount(1)
        ->and($page['guests'][0])->toMatchArray(['name' => 'Brandt.L', 'source' => 'staff', 'staffGuest' => true, 'makeupMissing' => false]);
});

it('never changes the host\'s numbers, KPIs, strength, JSAM counts or makeups, nor puts staff on its roster', function () {
    $kpis = app(KpiService::class);
    $platoon = $this->tree['units']['platoon'];
    $before = [$kpis->forUnit($this->host, $this->week), $kpis->forUnit($platoon, $this->week), $kpis->forUnit($this->far, $this->week)];
    $rosterBefore = $this->actingAs($this->tree['users']['admin'])->get(route('rollcall.show', ['unit' => $this->host->id, 'week' => '2026-10-03']))->viewData('page')['props']['troopers'];

    sendStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id, $this->far->id]);

    $sightings = app(CrossSectionSightingRepository::class);
    expect($kpis->forUnit($this->host, $this->week))->toEqual($before[0])
        ->and($kpis->forUnit($platoon, $this->week))->toEqual($before[1])
        ->and($kpis->forUnit($this->far, $this->week))->toEqual($before[2])
        ->and(Attendance::query()->count())->toBe(0)
        ->and($sightings->visitsWithin($this->tree['units']['regiment'], $this->week->subWeeks(11), $this->week))->toHaveCount(0)
        ->and($sightings->trooperIdsSeenInWeek([$this->psg->id], $this->week))->toBe([])
        ->and(app(PracticeGuests::class)->forUnit($platoon, $this->week))->toBe([]);

    $roster = $this->actingAs($this->tree['users']['admin'])->get(route('rollcall.show', ['unit' => $this->host->id, 'week' => '2026-10-03']))->viewData('page')['props']['troopers'];
    expect(collect($roster)->pluck('id')->all())->toBe(collect($rosterBefore)->pluck('id')->all())
        ->not->toContain($this->psg->id);
});
