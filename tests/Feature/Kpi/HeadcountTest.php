<?php

use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Kpi\ReportSnapshot;
use App\Kpi\RollupKpis;
use App\Kpi\WeeklyReportKpis;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The headcount (#424): everyone serving placed under a unit, HQ staff and groups included, New Recruits only on their
// own group. It sits beside the measured strength (the rollcall population) and never replaces it: RC, SP, at-risk and the
// roll-up equality are computed from the sections alone.

function hcWeek(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
}

function hcPlace(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->s1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->s2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->recruits = $units->create(UnitType::Group, 'New Recruits', 'NR', $this->regiment->id, UnitKind::Recruit);
    $this->staff = $units->create(UnitType::Group, 'S1 Staff', 'S1', $this->battalion->id, UnitKind::Staff);

    hcPlace($this->s1);
    hcPlace($this->s1);
    hcPlace($this->s2);
    hcPlace($this->platoon);     // HQ staff on the platoon
    hcPlace($this->company);     // HQ staff on the company
    hcPlace($this->staff);       // a staff department under the battalion
    hcPlace($this->recruits);    // a New Recruit
    hcPlace($this->s2, ['status' => TrooperStatus::Inactive]); // not serving: not counted
});

it('counts HQ staff and groups under a unit, New Recruits only on their own group', function () {
    $headcount = app(Headcount::class);

    expect($headcount->forUnit($this->s1))->toBe(2)
        ->and($headcount->forUnit($this->platoon))->toBe(4)
        ->and($headcount->forUnit($this->company))->toBe(5)
        ->and($headcount->forUnit($this->battalion))->toBe(6)
        ->and($headcount->forUnit($this->regiment))->toBe(6)
        ->and($headcount->forUnit($this->recruits))->toBe(1);
});

it('counts ELOA and Reservists only on their own group, never above it', function () {
    $units = app(UnitRepository::class);
    $eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa);
    $reserve = $units->create(UnitType::Group, 'Reservists', 'RES', $this->regiment->id, UnitKind::Reserve);
    hcPlace($eloa, ['status' => TrooperStatus::Eloa]);
    hcPlace($reserve, ['status' => TrooperStatus::Reserve]);
    hcPlace($reserve, ['status' => TrooperStatus::Reserve]);
    $headcount = app(Headcount::class);

    expect($headcount->forUnit($this->regiment))->toBe(6)
        ->and($headcount->forUnit($eloa))->toBe(1)
        ->and($headcount->forUnit($reserve))->toBe(2);
});

it('leaves the measured strength, and the roll-up equality, as they were', function () {
    $kpis = app(KpiService::class);
    $reports = app(WeeklyReportRepository::class);
    $sender = User::factory()->create();

    foreach ([$this->s1, $this->s2] as $section) {
        $reports->send($section->id, hcWeek(), (new ReportSnapshot($kpis->forUnit($section, hcWeek()), null))->toArray(), 'notes', $sender->id, 'digest', null);
    }

    $live = $kpis->forUnit($this->platoon, hcWeek());

    expect($live->strength)->toBe(3)
        ->and(app(RollupKpis::class)->forUnit($this->platoon, hcWeek())->kpis)->toEqual($live)
        ->and(app(Headcount::class)->forUnit($this->platoon))->toBe(4);
});

it('freezes the headcount into a report snapshot and reads it back as it was sent', function () {
    $snapshot = app(WeeklyReportKpis::class)->snapshot($this->platoon, hcWeek());

    expect($snapshot->headcount)->toBe(4);

    $stored = $snapshot->toArray();
    // Someone joins after the send: the frozen figure does not move.
    hcPlace($this->platoon);

    expect(ReportSnapshot::fromArray($stored)->headcount)->toBe(4)
        ->and(app(Headcount::class)->forUnit($this->platoon))->toBe(5);
});

it('reads a snapshot frozen before the headcount existed as having none', function () {
    $legacy = collect((new ReportSnapshot(app(KpiService::class)->forUnit($this->s1, hcWeek()), null))->toArray())->except('headcount')->all();

    expect(ReportSnapshot::fromArray($legacy)->headcount)->toBeNull();
});

it('persists a sent platoon report\'s headcount, staff included, apart from its strength, and keeps it', function () {
    $admin = User::factory()->admin()->create();
    $send = fn (Unit $unit) => app(SendWeeklyReportHandler::class)->handle($admin, new SendWeeklyReport($unit->id, hcWeek(), 'Weekly report', 'ok'));

    $send($this->s1);
    $send($this->s2);
    $send($this->platoon);
    hcPlace($this->platoon);

    $stored = WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole()->kpis;

    expect($stored['strength'])->toBe(3)
        ->and($stored['headcount'])->toBe(4)
        ->and(ReportSnapshot::fromArray($stored)->headcount)->toBe(4);
});
