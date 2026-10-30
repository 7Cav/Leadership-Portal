<?php

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// The strength trends page: a unit's headcount and in roll call per Cav week. A sent week reads its frozen snapshot, an
// unsent ended week is rebuilt from the placement history, and a week before the history with no report is a gap.
// Now is Wednesday 2026-11-25, so the running week opens 2026-11-21 and the history covers 2026-10-24 on.
//   7CAV ─ 1/A/1-7 (platoon, HQ staff) ─┬─ 1/1/A/1-7
//                                        └─ 2/1/A/1-7

function stPlace(Unit $unit): Trooper
{
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

/** @return array<string, mixed> the page props for `$unit` */
function stProps($test, Unit $unit, string $range = '12', ?User $as = null): array
{
    return $test->actingAs($as ?? $test->admin)->get("/roster/{$unit->id}/strength?range={$range}")->assertOk()->viewData('page')['props'];
}

function stWeek(array $props, string $date): array
{
    return collect($props['weeks'])->firstWhere('week', $date);
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->regiment->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->admin = User::factory()->admin()->create();

    stPlace($this->section);
    stPlace($this->section);
    stPlace($this->section2);
    stPlace($this->platoon); // HQ staff: in the headcount, not in roll call

    // Joins 1st Section on Tuesday 2026-11-10, in the week of 2026-11-07.
    $joiner = stPlace($this->section);
    app(TrooperPlacementRepository::class)->record([$joiner->id => [null, $this->section->id]], PlacementSource::Sync, CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('rebuilds unsent ended weeks from the placement history', function () {
    $props = stProps($this, $this->platoon);

    expect(stWeek($props, '2026-10-24'))->toMatchArray(['headcount' => 4, 'strength' => 3, 'source' => 'history'])
        ->and(stWeek($props, '2026-11-07'))->toMatchArray(['headcount' => 5, 'strength' => 4, 'in' => 1, 'out' => 0, 'source' => 'history'])
        ->and(stWeek($props, '2026-11-21'))->toMatchArray(['headcount' => 5, 'strength' => 4, 'source' => 'live'])
        ->and($props['change'])->toBe(['headcount' => 1, 'strength' => 1]);
});

it('reads a sent week from its frozen snapshot', function () {
    WeeklyReport::factory()->sent()->create([
        'unit_id' => $this->platoon->id,
        'week_start' => CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'),
        'kpis' => ['strength' => 7, 'headcount' => 9],
    ]);
    // A sent report from before the history: its week is known, the weeks around it are not.
    WeeklyReport::factory()->sent()->create([
        'unit_id' => $this->platoon->id,
        'week_start' => CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC'),
        'kpis' => ['strength' => 6, 'headcount' => 8],
    ]);

    $props = stProps($this, $this->platoon);

    expect(stWeek($props, '2026-10-31'))->toMatchArray(['headcount' => 9, 'strength' => 7, 'source' => 'report'])
        ->and(stWeek($props, '2026-10-10'))->toMatchArray(['headcount' => 8, 'strength' => 6, 'source' => 'report']);
});

it('leaves a gap for a week nothing is known about, never a zero', function () {
    $props = stProps($this, $this->platoon);

    expect($props['weeks'])->toHaveCount(12)
        ->and(stWeek($props, '2026-10-17'))->toMatchArray(['headcount' => null, 'strength' => null, 'in' => null, 'out' => null, 'source' => null])
        // The first rebuilt week has no rebuilt week before it, so its joins are not known either.
        ->and(stWeek($props, '2026-10-24')['in'])->toBeNull();
});

it('shows the children headcounts for comparison', function () {
    $props = stProps($this, $this->platoon);
    $section = collect($props['children'])->firstWhere('id', $this->section->id);

    expect(collect($props['children'])->pluck('id')->all())->toBe([$this->section->id, $this->section2->id])
        ->and(collect($section['points'])->firstWhere('week', '2026-10-24')['headcount'])->toBe(2)
        ->and(collect($section['points'])->firstWhere('week', '2026-11-14')['headcount'])->toBe(3);
});

it('refuses a unit outside the viewer scope', function () {
    // A serving Section Leader of 2nd Section reads their own section, not the platoon above it.
    $this->actingAs(User::factory()->commanding($this->section2)->create())->get("/roster/{$this->platoon->id}/strength")->assertForbidden();
    $this->actingAs($this->admin)->get('/roster/999999/strength')->assertForbidden();
});

it('reads in a fixed number of queries whatever the range or the size of the unit', function () {
    $count = function (string $range) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        stProps($this, $this->platoon, $range);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count('12'); // warm up whatever a first request reads once
    $small = $count('12');

    $units = app(UnitRepository::class);
    foreach (range(3, 6) as $n) {
        $more = $units->create(UnitType::Section, "Section {$n}", "{$n}/1/A/1-7", $this->platoon->id);
        stPlace($more);
        stPlace($more);
        WeeklyReport::factory()->sent()->create([
            'unit_id' => $more->id,
            'week_start' => CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'),
            'kpis' => ['strength' => 2, 'headcount' => 2],
        ]);
    }

    expect($count('all'))->toBe($small)
        ->and($count('52'))->toBe($small);
});
