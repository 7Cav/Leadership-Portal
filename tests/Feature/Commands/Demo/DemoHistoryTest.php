<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Demo\DemoAttendancePattern;
use App\Commands\Demo\DemoHistoryForbiddenException;
use App\Commands\Demo\GenerateDemoHistory;
use App\Commands\Demo\GenerateDemoHistoryHandler;
use App\Commands\Demo\PurgeDemoHistory;
use App\Commands\Demo\PurgeDemoHistoryHandler;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\KpiService;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// #115: demo history for the real roster — generated from the placed troopers and the imported
// sections, tagged `is_demo`, deterministic per (section, week), one audited transaction per week,
// and purged exactly.
//
// Fixture (all built through UnitRepository so `path` is real):
//   1-7 ─┬─ A/1-7 ─┬─ 1/A/1-7 ─┬─ 1/1/A/1-7  (6 troopers, one inactive extra)
//        │         │           └─ 2/1/A/1-7  (3 troopers)
//        │         └─ 2/A/1-7 ─┬─ 1/2/A/1-7  (5 troopers)
//        │                     └─ 2/2/A/1-7  (nobody placed)
//        └─ B/1-7 ─── 1/B/1-7 ─── 1/1/B/1-7  (2 troopers — outside A/1-7)
// "Now" is Wednesday 2026-10-07, in the Cav week opening Saturday 2026-10-03, so the last three
// completed weeks open 09-12, 09-19 and 09-26.

function demoNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
}

function demoWeek(string $date): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} 00:00:00", 'UTC');
}

function generateDemo(User $actor, Unit $unit, int $weeks = 3)
{
    return app(GenerateDemoHistoryHandler::class)->handle($actor, new GenerateDemoHistory($unit->id, $weeks, demoNow()));
}

function purgeDemo(User $actor, Unit $unit)
{
    return app(PurgeDemoHistoryHandler::class)->handle($actor, new PurgeDemoHistory($unit->id));
}

/** Every attendance row as a comparable fact, independent of ids and timestamps. */
function attendanceFacts(): array
{
    return Attendance::query()->with('sectionPractice')->get()
        ->map(fn (Attendance $row) => [
            $row->trooper_id,
            $row->sectionPractice->week_start->toDateString(),
            $row->status?->value,
            $row->rollcall->value,
            $row->is_demo,
        ])
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->s11 = $units->create(UnitType::Section, '1st Squad', '1/1/A/1-7', $this->platoon1->id);
    $this->s21 = $units->create(UnitType::Section, '2nd Squad', '2/1/A/1-7', $this->platoon1->id);
    $this->s12 = $units->create(UnitType::Section, '1st Squad', '1/2/A/1-7', $this->platoon2->id);
    $this->s22 = $units->create(UnitType::Section, '2nd Squad', '2/2/A/1-7', $this->platoon2->id);
    $bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $bravoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $bravo->id);
    $this->outside = $units->create(UnitType::Section, '1st Squad', '1/1/B/1-7', $bravoPlatoon->id);

    Trooper::factory()->count(6)->create(['primary_section_id' => $this->s11->id]);
    Trooper::factory()->create(['primary_section_id' => $this->s11->id, 'status' => TrooperStatus::Inactive]);
    Trooper::factory()->count(3)->create(['primary_section_id' => $this->s21->id]);
    Trooper::factory()->count(5)->create(['primary_section_id' => $this->s12->id]);
    Trooper::factory()->count(2)->create(['primary_section_id' => $this->outside->id]);

    $this->admin = User::factory()->admin()->create();
});

it('writes a demo practice and one attendance row per placed trooper for each completed week', function () {
    $result = generateDemo($this->admin, $this->company);

    expect(array_map(fn ($week) => $week->toDateString(), $result->generatedWeeks))->toBe(['2026-09-12', '2026-09-19', '2026-09-26'])
        ->and($result->skippedWeeks)->toBe([])
        ->and($result->troopers)->toBe(14)
        ->and($result->sections)->toBe(3)
        ->and($result->attendance)->toBe(42);

    // Three populated sections × three weeks; the empty section has no practice to mark.
    expect(SectionPractice::query()->count())->toBe(9)
        ->and(SectionPractice::query()->where('is_demo', false)->count())->toBe(0)
        ->and(SectionPractice::query()->where('section_id', $this->s22->id)->count())->toBe(0)
        ->and(SectionPractice::query()->where('section_id', $this->outside->id)->count())->toBe(0)
        // The current week is left for the live demo.
        ->and(SectionPractice::query()->where('week_start', demoWeek('2026-10-03'))->count())->toBe(0);

    $rows = Attendance::query()->get();
    expect($rows)->toHaveCount(42)
        ->and($rows->every(fn (Attendance $row) => $row->is_demo && $row->source === AttendanceSource::Manual && $row->status !== null))->toBeTrue()
        ->and($rows->contains(fn (Attendance $row) => $row->status === AttendanceStatus::Loa))->toBeFalse()
        // The inactive trooper is not on the roster any more, so has no history generated.
        ->and($rows->pluck('trooper_id')->unique())->toHaveCount(14);
});

it('sends every section each week, and the platoons and company for all but the most recent week', function () {
    $result = generateDemo($this->admin, $this->company);

    // 4 sections × 3 weeks, plus 2 platoons + 1 company × 2 weeks.
    expect($result->reports)->toBe(18)
        ->and(WeeklyReport::query()->count())->toBe(18)
        ->and(WeeklyReport::query()->get()->every(fn (WeeklyReport $r) => $r->is_demo
            && $r->status === WeeklyReportStatus::Sent
            && $r->sent_by === $this->admin->id
            && $r->notes === GenerateDemoHistoryHandler::NOTES))->toBeTrue();

    $latest = demoWeek('2026-09-26');
    expect(WeeklyReport::query()->where('week_start', $latest)->pluck('unit_id')->sort()->values()->all())
        ->toBe(collect([$this->s11, $this->s21, $this->s12, $this->s22])->pluck('id')->sort()->values()->all())
        // The battalion is above the chosen unit, so it is never sent.
        ->and(WeeklyReport::query()->where('unit_id', $this->battalion->id)->exists())->toBeFalse();
});

it('freezes section KPIs from the shared service and rolls the platoons and company up from them', function () {
    generateDemo($this->admin, $this->company);
    $week = demoWeek('2026-09-19');
    $report = fn (Unit $unit, CarbonImmutable $at) => WeeklyReport::query()->where('unit_id', $unit->id)->where('week_start', $at)->sole();

    $section = ReportSnapshot::fromArray($report($this->s11, $week)->kpis);
    expect($section->kpis)->toEqual(app(KpiService::class)->forUnit($this->s11, $week))
        // The first generated week is a first report; later ones carry the week-over-week delta.
        ->and(ReportSnapshot::fromArray($report($this->s11, demoWeek('2026-09-12'))->kpis)->delta)->toBeNull()
        ->and($section->delta)->not->toBeNull();

    $platoon = ReportSnapshot::fromArray($report($this->platoon1, $week)->kpis)->kpis;
    expect($platoon)->toEqual(UnitKpis::fromArray($report($this->s11, $week)->kpis)->plus(UnitKpis::fromArray($report($this->s21, $week)->kpis)));

    $company = ReportSnapshot::fromArray($report($this->company, $week)->kpis)->kpis;
    expect($company->strength)->toBe(14)
        ->and($company)->toEqual($platoon->plus(UnitKpis::fromArray($report($this->platoon2, $week)->kpis)));

    $stored = $report($this->s11, $week);
    expect($stored->content_digest)->toBe(SendWeeklyReportHandler::contentDigest($this->s11->id, $week, $stored->kpis, $stored->notes, null));
});

it('looks plausible: most sign and attend, and the chosen trooper is flagged at risk', function () {
    generateDemo($this->admin, $this->company, weeks: 4);
    $rows = Attendance::query()->get();

    expect($rows->filter(fn ($r) => $r->rollcall === RollcallSignature::WillAttend)->count())->toBeGreaterThan($rows->count() / 2)
        ->and($rows->filter(fn ($r) => $r->status === AttendanceStatus::Present)->count())->toBeGreaterThan($rows->count() / 2)
        ->and($rows->contains(fn ($r) => $r->status === AttendanceStatus::Missed))->toBeTrue();

    $placed = Trooper::query()->where('primary_section_id', $this->s11->id)->where('status', '!=', 'inactive')->orderBy('id')->pluck('id')->all();
    $atRisk = DemoAttendancePattern::atRiskTrooper($this->s11->id, $placed);
    $flags = app(KpiService::class)->trooperAttendance($this->s11, demoWeek('2026-09-26'));

    expect($atRisk)->not->toBeNull()
        ->and($flags->get($atRisk)->atRisk())->toBeTrue()
        // Too small a section to single anyone out.
        ->and(DemoAttendancePattern::atRiskTrooper($this->s21->id, Trooper::query()->where('primary_section_id', $this->s21->id)->pluck('id')->all()))->toBeNull();
});

it('is idempotent and deterministic: a rerun replaces the demo rows with the same pattern', function () {
    generateDemo($this->admin, $this->company);
    $first = attendanceFacts();

    generateDemo($this->admin, $this->company);

    expect(attendanceFacts())->toBe($first)
        ->and(SectionPractice::query()->count())->toBe(9)
        ->and(WeeklyReport::query()->count())->toBe(18);

    purgeDemo($this->admin, $this->company);
    generateDemo($this->admin, $this->company);

    expect(attendanceFacts())->toBe($first);
});

it('never creates or alters troopers, units or users', function () {
    $snapshot = fn () => [
        Trooper::query()->orderBy('id')->get()->toArray(),
        Unit::query()->orderBy('id')->get()->toArray(),
        User::query()->orderBy('id')->get()->toArray(),
    ];
    $before = $snapshot();

    generateDemo($this->admin, $this->company);
    purgeDemo($this->admin, $this->company);

    expect($snapshot())->toBe($before);
});

it('skips a week that already holds real attendance, and reports it', function () {
    app(MarkAttendanceHandler::class)->handle($this->admin, new MarkAttendance(
        $this->s21->id,
        demoWeek('2026-09-19')->addDays(3),
        new AttendanceMark(Trooper::query()->where('primary_section_id', $this->s21->id)->value('id'), AttendanceStatus::Present),
    ));
    $real = Attendance::query()->sole();

    $result = generateDemo($this->admin, $this->company);

    expect(array_map(fn ($w) => $w->toDateString(), $result->skippedWeeks))->toBe(['2026-09-19'])
        ->and(array_map(fn ($w) => $w->toDateString(), $result->generatedWeeks))->toBe(['2026-09-12', '2026-09-26'])
        ->and($result->summary())->toContain('Skipped 2026-09-19')
        // Nothing demo lands in the real week, and the real mark is untouched.
        ->and(SectionPractice::query()->where('week_start', demoWeek('2026-09-19'))->where('is_demo', true)->exists())->toBeFalse()
        ->and(WeeklyReport::query()->where('week_start', demoWeek('2026-09-19'))->exists())->toBeFalse()
        ->and($real->fresh()->toArray())->toBe($real->toArray());

    purgeDemo($this->admin, $this->company);

    expect(Attendance::query()->sole()->id)->toBe($real->id)
        ->and(SectionPractice::query()->count())->toBe(1);
});

it('skips a week that already holds a real report', function () {
    WeeklyReport::factory()->create(['unit_id' => $this->platoon2->id, 'week_start' => demoWeek('2026-09-12')]);

    $result = generateDemo($this->admin, $this->company);

    expect(array_map(fn ($w) => $w->toDateString(), $result->skippedWeeks))->toBe(['2026-09-12']);
});

it('generates nothing, unaudited, for a unit with no placed troopers', function () {
    $result = generateDemo($this->admin, $this->s22);

    expect($result->hasNoTroopers())->toBeTrue()
        ->and($result->summary())->toBe("No troopers are placed in 2/2/A/1-7's sections, so no demo history was generated.")
        ->and(SectionPractice::query()->count() + WeeklyReport::query()->count() + AuditLogEntry::query()->count())->toBe(0);
});

it('audits one entry per generated week, by the actor, targeting the chosen unit', function () {
    generateDemo($this->admin, $this->company);

    $entries = AuditLogEntry::query()->orderBy('id')->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('action')->unique()->all())->toBe([GenerateDemoHistoryHandler::ACTION])
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->pluck('target_type')->unique()->all())->toBe(['Unit'])
        ->and($entries->pluck('target_id')->unique()->all())->toBe([(string) $this->company->id])
        ->and($entries->first()->reason)->toBe('Demo history for A/1-7, week of 2026-09-12');
});

it('sends the whole chain when generated for the battalion', function () {
    $result = generateDemo($this->admin, $this->battalion, weeks: 2);

    // 5 sections × 2 weeks + (3 platoons + 2 companies + battalion) × 1 week.
    expect($result->reports)->toBe(16)
        ->and(WeeklyReport::query()->where('unit_id', $this->battalion->id)->count())->toBe(1)
        ->and(ReportSnapshot::fromArray(WeeklyReport::query()->where('unit_id', $this->battalion->id)->sole()->kpis)->kpis->strength)->toBe(16);
});

it('refuses a non-Admin, even one with scope over the unit', function () {
    $leader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->company->id]);

    expect(fn () => generateDemo($leader, $this->company))->toThrow(DemoHistoryForbiddenException::class)
        ->and(fn () => purgeDemo($leader, $this->company))->toThrow(DemoHistoryForbiddenException::class);
});

it('refuses a unit that does not exist', function () {
    expect(fn () => app(GenerateDemoHistoryHandler::class)->handle($this->admin, new GenerateDemoHistory(99999, 1, demoNow())))
        ->toThrow(DemoHistoryForbiddenException::class);
});

it('refuses a week count outside 1 to 12', function (int $weeks) {
    expect(fn () => new GenerateDemoHistory($this->company->id, $weeks))->toThrow(InvalidArgumentException::class);
})->with([0, 13, -1]);

it('purges exactly the demo rows of the subtree, audited', function () {
    generateDemo($this->admin, $this->battalion, weeks: 2);
    $bravoReports = WeeklyReport::query()->whereNotIn('unit_id', app(UnitRepository::class)->subtreeIds($this->company))->count();

    $result = purgeDemo($this->admin, $this->company);

    expect($result->deleted->attendance)->toBe(28)
        ->and($result->deleted->practices)->toBe(6)
        // 4 sections × 2 + (2 platoons + company) × 1.
        ->and($result->deleted->reports)->toBe(11)
        ->and($result->deleted->weeks)->toBe(2)
        // Outside A/1-7 — Bravo and the battalion — nothing is touched.
        ->and(WeeklyReport::query()->count())->toBe($bravoReports)
        ->and(SectionPractice::query()->pluck('section_id')->unique()->all())->toBe([$this->outside->id]);

    $entry = AuditLogEntry::query()->latest('id')->first();
    expect($entry->action)->toBe(PurgeDemoHistoryHandler::ACTION)
        ->and($entry->target_id)->toBe((string) $this->company->id)
        ->and($entry->actor_id)->toBe($this->admin->id);
});

it('keeps a demo practice a leader has since marked for real', function () {
    generateDemo($this->admin, $this->s21, weeks: 1);
    $practice = SectionPractice::query()->sole();
    $newcomer = Trooper::factory()->create(['primary_section_id' => $this->s21->id]);
    Attendance::query()->create(['trooper_id' => $newcomer->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);

    purgeDemo($this->admin, $this->s21);

    expect(SectionPractice::query()->sole()->id)->toBe($practice->id)
        ->and(Attendance::query()->sole()->trooper_id)->toBe($newcomer->id);
});

it('is a no-op, unaudited, when there is nothing to purge', function () {
    $result = purgeDemo($this->admin, $this->company);

    expect($result->deleted->isEmpty())->toBeTrue()
        ->and($result->summary())->toBe('There is no demo history for A/1-7 to purge.')
        ->and(AuditLogEntry::query()->count())->toBe(0);
});
