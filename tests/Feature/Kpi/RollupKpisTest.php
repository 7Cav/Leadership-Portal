<?php

use App\Kpi\ChildReport;
use App\Kpi\ChildReportStatus;
use App\Kpi\Ratio;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\RollupKpis;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// 5.7 / AD-13, AD-21: a non-leaf report is the sum of its DIRECT children's SENT snapshots —
// never a live re-aggregation, never a grandchild's report, and never a draft child's numbers.
// Fixture tree:
//   Alpha Company ─┬─ 1st Platoon ─ 1st Squad
//                  ├─ 2nd Platoon
//                  └─ 3rd Platoon
// `week_start` is 2026-06-27 (a Saturday).

function rollupWeek(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC');
}

function rollupKpis(int $strength, array $rc, array $sp, int $atRisk = 0, int $onLoa = 0, int $makeups = 0): UnitKpis
{
    return new UnitKpis($strength, new Ratio(...$rc), new Ratio(...$sp), $atRisk, $onLoa, $makeups);
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->first = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->second = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->third = $units->create(UnitType::Platoon, '3rd Platoon', '3/A/1-7', $this->company->id);
    $this->squad = $units->create(UnitType::Section, '1st Squad', '1/1/A/1-7', $this->first->id);
    // Every platoon takes part, so none is left out of the roll-up (#442, #478): 1st Platoon through a squad with a
    // trooper in it (HQ staff alone would not do, with a section under it), 2nd and 3rd, which have no sections,
    // through their own troopers.
    manUnits($this->squad, $this->second, $this->third);

    $this->reports = app(WeeklyReportRepository::class);
    $this->sender = User::factory()->create();
    $this->send = fn (Unit $unit, UnitKpis $kpis, ?CarbonImmutable $week = null) => $this->reports
        ->send($unit->id, $week ?? rollupWeek(), $kpis->toArray(), null, $this->sender->id, 'digest', null);
    $this->rollup = app(RollupKpis::class);
});

it('sums the direct children\'s sent snapshots, composing RC and SP from their parts', function () {
    ($this->send)($this->first, rollupKpis(4, [4, 4], [6, 8], atRisk: 1, onLoa: 1, makeups: 2));
    ($this->send)($this->second, rollupKpis(6, [1, 3], [2, 12], atRisk: 2, onLoa: 0, makeups: 1));
    ($this->send)($this->third, rollupKpis(5, [10, 20], [0, 0], atRisk: 0, onLoa: 2, makeups: 0));

    $result = $this->rollup->forUnit($this->company, rollupWeek());

    expect($result->kpis)->toEqual(rollupKpis(15, [15, 27], [8, 20], atRisk: 3, onLoa: 3, makeups: 3))
        // 15/27, not the mean of 100%, 33% and 50%.
        ->and($result->kpis->rc30d->value())->toBe(15 / 27)
        ->and($result->isComplete())->toBeTrue()
        ->and($result->missingChildren())->toBe([]);
});

it('lists every direct child in order with its status, and the KPIs only of a sent one', function () {
    $firstKpis = rollupKpis(4, [3, 4], [2, 4]);
    ($this->send)($this->first, $firstKpis);
    $this->reports->saveDraft($this->second->id, rollupWeek(), rollupKpis(9, [9, 9], [9, 9])->toArray(), null);

    $children = $this->rollup->forUnit($this->company, rollupWeek())->children;

    expect(array_map(fn (ChildReport $c) => [$c->unit->id, $c->status, $c->kpis], $children))->toEqual([
        [$this->first->id, ChildReportStatus::Sent, $firstKpis],
        [$this->second->id, ChildReportStatus::Draft, null],
        [$this->third->id, ChildReportStatus::NotStarted, null],
    ]);
});

it('excludes a draft or unstarted child from the aggregate and names it as holding up the chain', function () {
    ($this->send)($this->first, rollupKpis(4, [3, 4], [2, 4], atRisk: 1));
    $this->reports->saveDraft($this->second->id, rollupWeek(), rollupKpis(9, [9, 9], [9, 9], atRisk: 9)->toArray(), null);

    $result = $this->rollup->forUnit($this->company, rollupWeek());

    expect($result->kpis)->toEqual(rollupKpis(4, [3, 4], [2, 4], atRisk: 1))
        ->and($result->isComplete())->toBeFalse()
        ->and(array_map(fn (ChildReport $c) => [$c->unit->name, $c->status], $result->missingChildren()))->toBe([
            ['2nd Platoon', ChildReportStatus::Draft],
            ['3rd Platoon', ChildReportStatus::NotStarted],
        ]);
});

it('never substitutes live attendance for a child that has not sent', function () {
    // 3rd Platoon has troopers and marked attendance this week, but no sent report.
    $section = app(UnitRepository::class)->create(UnitType::Section, '1st Squad', '1/3/A/1-7', $this->third->id);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, rollupWeek());
    foreach (range(1, 3) as $i) {
        $trooper = Trooper::factory()->create();
        $trooper->forceFill(['primary_section_id' => $section->id])->save();
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, AttendanceStatus::Makeup, AttendanceSource::Manual);
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, RollcallSignature::WillAttend);
    }

    $result = $this->rollup->forUnit($this->company, rollupWeek());

    expect($result->kpis)->toEqual(UnitKpis::none())
        ->and(collect($result->missingChildren())->pluck('unit.id')->all())
        ->toBe([$this->first->id, $this->second->id, $this->third->id]);
});

it('reads the direct children\'s snapshots, never a grandchild\'s', function () {
    ($this->send)($this->squad, rollupKpis(7, [7, 7], [7, 7], makeups: 7));

    $result = $this->rollup->forUnit($this->company, rollupWeek());

    expect($result->kpis)->toEqual(UnitKpis::none())
        ->and($result->children[0]->status)->toBe(ChildReportStatus::NotStarted);
});

it('reads only the asked week\'s snapshots', function () {
    ($this->send)($this->first, rollupKpis(4, [4, 4], [4, 4]), rollupWeek()->subWeek());

    expect($this->rollup->forUnit($this->company, rollupWeek())->children[0]->status)
        ->toBe(ChildReportStatus::NotStarted);
});

it('refuses a leaf unit — a section reports from attendance, not from children', function () {
    $this->rollup->forUnit($this->squad, rollupWeek());
})->throws(InvalidArgumentException::class, 'has no children');

it('refuses a week_start that is not a Saturday 00:00 UTC', function () {
    $this->rollup->forUnit($this->company, rollupWeek()->addDay());
})->throws(InvalidArgumentException::class, 'week_start must be a Saturday');

it('refuses a sent snapshot it cannot read as unavailable KPIs, rather than counting it as zero', function (array $snapshot) {
    $this->reports->send($this->first->id, rollupWeek(), $snapshot, null, $this->sender->id, 'd', null);

    expect(fn () => $this->rollup->forUnit($this->company, rollupWeek()))
        ->toThrow(ReportKpisUnavailableException::class, "unit {$this->first->id} for 2026-06-27");
})->with([
    'a wrong type' => [['strength' => ['value' => 4]]],
    'a fractional count' => [[...rollupKpis(4, [1, 2], [1, 2])->toArray(), 'strength' => 10.7]],
    'a boolean count' => [[...rollupKpis(4, [1, 2], [1, 2])->toArray(), 'makeups' => true]],
    'a numeric-string numerator' => [[...rollupKpis(4, [1, 2], [1, 2])->toArray(), 'sp_30d' => ['numerator' => '1', 'denominator' => 2]]],
    'a missing ratio' => [array_diff_key(rollupKpis(4, [1, 2], [1, 2])->toArray(), ['rc_30d' => true])],
    'a missing key' => [array_diff_key(rollupKpis(4, [1, 2], [1, 2])->toArray(), ['on_loa' => true])],
    'an impossible count' => [[...rollupKpis(4, [1, 2], [1, 2])->toArray(), 'at_risk' => 5]],
    'an impossible ratio' => [[...rollupKpis(4, [1, 2], [1, 2])->toArray(), 'rc_30d' => ['numerator' => 3, 'denominator' => 2]]],
]);

it('reads any number of children in a constant number of queries', function () {
    foreach (range(1, 12) as $i) {
        $platoon = app(UnitRepository::class)->create(UnitType::Platoon, "Platoon {$i}", null, $this->company->id);
        manUnits($platoon);
        if ($i % 2) {
            ($this->send)($platoon, rollupKpis(2, [1, 2], [1, 2]));
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $this->rollup->forUnit($this->company, rollupWeek());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result->children)->toHaveCount(15)
        ->and($result->kpis->strength)->toBe(12)
        // The children, their reports, and which of them have troopers now or had them that week (three).
        ->and($queries)->toBeLessThanOrEqual(5);
});

// 5.8: fromReports trusts the caller's collections only as far as it can check them.
it('refuses a report that is not the child\'s own for the week', function (string $case) {
    $other = ($this->send)($this->second, rollupKpis(1, [1, 1], [1, 1]), $case === 'week' ? rollupWeek()->subWeek() : null);
    $reports = collect([$this->first->id => $other]);

    RollupKpis::fromReports(collect([$this->first]), $reports, rollupWeek());
})->with(['another unit\'s report' => 'unit', 'another week\'s report' => 'week'])->throws(InvalidArgumentException::class);
