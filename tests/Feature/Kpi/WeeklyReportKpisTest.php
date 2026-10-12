<?php

use App\Kpi\KpiDelta;
use App\Kpi\KpiService;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Kpi\WeeklyReportKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;

// 5.6 / 5.4 / AD-21: the snapshot a weekly report freezes — the six KPIs from the shared service,
// plus the delta against the same unit's prior-week *sent* snapshot (null when there is none).

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', null);
    $this->section = $units->create(UnitType::Section, 'Section A', '1-1/A/1-7', $this->platoon->id);

    $this->week = CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
    $this->trooper = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->week);
    app(AttendanceRepository::class)->markPractice($practice, $this->trooper->id, AttendanceStatus::Makeup, AttendanceSource::Manual);

    $this->snapshots = app(WeeklyReportKpis::class);
});

it('takes a section\'s KPIs from the shared KPI service', function () {
    $snapshot = $this->snapshots->snapshot($this->section, $this->week);

    expect($snapshot->kpis)->toEqual(app(KpiService::class)->forUnit($this->section, $this->week));
});

it('has no delta on a unit\'s first report', function () {
    expect($this->snapshots->snapshot($this->section, $this->week)->delta)->toBeNull();
});

it('diffs against the prior week\'s sent snapshot, not against a live recompute of that week', function () {
    $priorKpis = app(KpiService::class)->forUnit($this->section, $this->week->subWeek());
    $prior = new ReportSnapshot(new UnitKpis(
        strength: 3, rc30d: $priorKpis->rc30d, sp30d: $priorKpis->sp30d, atRisk: 0, onLoa: 0, makeups: 0,
    ), null);
    app(WeeklyReportRepository::class)->send(
        $this->section->id, $this->week->subWeek(), $prior->toArray(), null, User::factory()->create()->id, 'digest', null,
    );

    $snapshot = $this->snapshots->snapshot($this->section, $this->week);

    expect($snapshot->delta)->toEqual(KpiDelta::between($snapshot->kpis, $prior->kpis))
        ->and($snapshot->delta->strength)->toBe(-2)  // the frozen 3, not the live 1
        ->and($snapshot->delta->makeups)->toBe(1);
});

it('has no delta when the prior week is only a draft', function () {
    app(WeeklyReportRepository::class)->saveDraft(
        $this->section->id, $this->week->subWeek(), (new ReportSnapshot(app(KpiService::class)->forUnit($this->section, $this->week->subWeek()), null))->toArray(), null,
    );

    expect($this->snapshots->snapshot($this->section, $this->week)->delta)->toBeNull();
});

it('ignores a sent report two weeks back — the delta is week over week', function () {
    app(WeeklyReportRepository::class)->send(
        $this->section->id, $this->week->subWeeks(2), (new ReportSnapshot(app(KpiService::class)->forUnit($this->section, $this->week->subWeeks(2)), null))->toArray(),
        null, User::factory()->create()->id, 'digest', null,
    );

    expect($this->snapshots->snapshot($this->section, $this->week)->delta)->toBeNull();
});

it('rolls a non-leaf unit up from its children\'s sent snapshots, naming the children that have not sent (5.7)', function () {
    $snapshot = $this->snapshots->snapshot($this->platoon, $this->week);

    expect($snapshot->kpis)->toEqual(UnitKpis::none()) // the section's live numbers are never substituted
        ->and($snapshot->missingChildren)->toBe([$this->section->id]);
});

it('refuses a non-leaf unit with no children to roll up', function () {
    $company = app(UnitRepository::class)->create(UnitType::Company, 'A Company', 'A/1-7', null);

    $this->snapshots->snapshot($company, $this->week);
})->throws(ReportKpisUnavailableException::class);

it('refuses a week_start that is not a Saturday 00:00 UTC', function () {
    $this->snapshots->snapshot($this->section, $this->week->addHour());
})->throws(InvalidArgumentException::class);

it('refuses, naming the unit and week, when the prior week\'s sent snapshot cannot be read', function (array $stored) {
    app(WeeklyReportRepository::class)->send(
        $this->section->id, $this->week->subWeek(), $stored, null, User::factory()->create()->id, 'digest', null,
    );

    expect(fn () => $this->snapshots->snapshot($this->section, $this->week))
        ->toThrow(ReportKpisUnavailableException::class, "Unit {$this->section->id}'s sent report for the week of 2026-06-20 cannot be read");
})->with([
    'not a report snapshot' => [['strength' => ['value' => 4]]],
    'a ratio stored as a number' => [['strength' => 4, 'rc_30d' => 5, 'sp_30d' => 5, 'at_risk' => 0, 'on_loa' => 0, 'makeups' => 0, 'delta' => null]],
    'a count stored as text' => [['strength' => 'x', 'rc_30d' => ['numerator' => 0, 'denominator' => 0], 'sp_30d' => ['numerator' => 0, 'denominator' => 0], 'at_risk' => 0, 'on_loa' => 0, 'makeups' => 0, 'delta' => null]],
]);
