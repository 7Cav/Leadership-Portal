<?php

use App\Kpi\StrengthTrends;
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

// The roster's Strength tab: a unit's headcount and in roll call per Cav week. A sent week reads its frozen snapshot, an
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

/** @return array<string, mixed> the Strength tab's props for `$unit` */
function stProps($test, Unit $unit, string $range = '12', ?User $as = null): array
{
    return $test->actingAs($as ?? $test->admin)->get("/roster/{$unit->id}?view=strength&range={$range}")->assertOk()->viewData('page')['props']['strengthTrends'];
}

/** The queries one StrengthTrends read takes. */
function stQueries(Unit $unit, ?int $weeks): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(StrengthTrends::class)->forUnit($unit, $weeks);
    DB::disableQueryLog();

    return count(DB::getQueryLog());
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

it('reads joins and departures from a snapshot that carries them', function () {
    WeeklyReport::factory()->sent()->create([
        'unit_id' => $this->platoon->id,
        'week_start' => CarbonImmutable::parse('2026-10-10 00:00:00', 'UTC'),
        'kpis' => ['strength' => 6, 'headcount' => 8, 'joined' => 2, 'left' => 1],
    ]);

    expect(stWeek(stProps($this, $this->platoon), '2026-10-10'))->toMatchArray(['in' => 2, 'out' => 1]);
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

it('sends the tab props only while the Strength tab is open', function () {
    $this->actingAs($this->admin);

    expect($this->get("/roster/{$this->platoon->id}")->assertOk()->viewData('page')['props']['strengthTrends'])->toBeNull()
        ->and($this->get("/roster/{$this->platoon->id}?view=matrix")->viewData('page')['props']['strengthTrends'])->toBeNull();

    $props = stProps($this, $this->platoon, '26');

    expect($props)->toHaveKeys(['range', 'weeks', 'change', 'children'])
        ->and($props['range'])->toBe('26')
        ->and($props['weeks'])->toHaveCount(26)
        ->and(stProps($this, $this->platoon, 'all')['range'])->toBe('all')
        ->and(stProps($this, $this->platoon, 'bogus')['range'])->toBe('12');
});

it('redirects the old strength address to the roster with the Strength tab open', function () {
    $this->actingAs($this->admin);

    $this->get("/roster/{$this->platoon->id}/strength")->assertRedirect("/roster/{$this->platoon->id}?view=strength");
    $this->get("/roster/{$this->platoon->id}/strength?range=52")->assertRedirect("/roster/{$this->platoon->id}?view=strength&range=52");
    $this->get("/roster/{$this->platoon->id}/strength?range=all")->assertRedirect("/roster/{$this->platoon->id}?view=strength&range=all");
});

it('refuses a unit outside the viewer scope', function () {
    // A serving Section Leader of 2nd Section reads their own section, not the platoon above it.
    $this->actingAs(User::factory()->commanding($this->section2)->create())->get("/roster/{$this->platoon->id}?view=strength")->assertForbidden();
    $this->actingAs($this->admin)->get('/roster/999999?view=strength')->assertForbidden();
});

it('gives a battalion its companies with their platoons under them, and a company none', function () {
    $units = app(UnitRepository::class);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $units->move($this->platoon, $company->id);
    $platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    stPlace($units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $platoon2->id));
    WeeklyReport::factory()->sent()->create([
        'unit_id' => $platoon2->id,
        'week_start' => CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'),
        'kpis' => ['strength' => 6, 'headcount' => 6],
    ]);

    $alpha = collect(stProps($this, $battalion)['children'])->firstWhere('id', $company->id);
    $platoons = collect($alpha['children']);
    $first = collect($platoons->firstWhere('id', $this->platoon->id)['points']);
    $second = collect($platoons->firstWhere('id', $platoon2->id)['points']);

    expect($platoons->pluck('id')->all())->toBe([$this->platoon->id, $platoon2->id])
        ->and($platoons->firstWhere('id', $this->platoon->id)['designation'])->toBe('1/A/1-7')
        ->and($first->firstWhere('week', '2026-11-14')['headcount'])->toBe(5)
        // A sent platoon week reads its snapshot, like every other series.
        ->and($second->firstWhere('week', '2026-10-31')['headcount'])->toBe(6)
        ->and($second->firstWhere('week', '2026-11-21')['headcount'])->toBe(1)
        // Below a battalion the comparison stops at the children.
        ->and(collect(stProps($this, $company)['children'])->every(fn ($c) => $c['children'] === []))->toBeTrue();
});

it('reads in a fixed number of queries whatever the range or the size of the unit', function () {
    stQueries($this->platoon, 12); // warm up whatever a first read takes once
    $small = stQueries($this->platoon, 12);

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

    expect($small)->toBeLessThanOrEqual(3)
        ->and(stQueries($this->platoon, null))->toBe($small)
        ->and(stQueries($this->platoon, 52))->toBe($small);
});

it('reads a battalion with its platoons in the same fixed number of queries', function () {
    $units = app(UnitRepository::class);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $units->move($this->platoon, $company->id);

    stQueries($battalion, 12);
    $small = stQueries($battalion, 12);

    foreach (['B', 'C'] as $letter) {
        $more = $units->create(UnitType::Company, "{$letter} Company", "{$letter}/1-7", $battalion->id);
        foreach (range(1, 3) as $n) {
            $platoon = $units->create(UnitType::Platoon, "Platoon {$n}", "{$n}/{$letter}/1-7", $more->id);
            stPlace($units->create(UnitType::Section, 'Section', "1/{$n}/{$letter}/1-7", $platoon->id));
            WeeklyReport::factory()->sent()->create([
                'unit_id' => $platoon->id,
                'week_start' => CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'),
                'kpis' => ['strength' => 1, 'headcount' => 1],
            ]);
        }
    }

    expect($small)->toBeLessThanOrEqual(3)
        ->and(stQueries($battalion, null))->toBe($small);
});
