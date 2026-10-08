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

it('refuses a non-leaf unit until the roll-up of its children\'s sent snapshots lands (5.7)', function () {
    $this->snapshots->snapshot($this->platoon, $this->week);
})->throws(ReportKpisUnavailableException::class);

it('refuses a week_start that is not a Saturday 00:00 UTC', function () {
    $this->snapshots->snapshot($this->section, $this->week->addHour());
})->throws(InvalidArgumentException::class);
