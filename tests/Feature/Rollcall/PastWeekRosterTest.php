<?php

use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The 2026-10-29 audit: a week that has ended keeps the roster it ended with. Its rollcall, the marks it accepts, its
// report and its KPIs all read who the placement history puts in the section when the week ended, so a move or a
// departure between the end of a week and its report (due 23:59 the Saturday after) strands nothing. The running week
// keeps "placed now".

function pastWeekAt(string $moment): void
{
    Carbon::setTestNow(CarbonImmutable::parse($moment, 'UTC'));
}

function pastWeekIds($response): array
{
    return collect($response->viewData('page')['props']['troopers'])->pluck('id')->all();
}

function pastWeekRow($response, int $trooperId): ?array
{
    return collect($response->viewData('page')['props']['troopers'])->firstWhere('id', $trooperId);
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $reg = $units->create(UnitType::Regiment, 'Reg', null, null);
    $bn = $units->create(UnitType::Battalion, 'Bn', '1-7', $reg->id);
    $this->platoon = $units->create(UnitType::Platoon, 'Plt', '1/A/1-7', $bn->id);
    $this->a = $units->create(UnitType::Section, 'A', '1/1/A/1-7', $this->platoon->id);
    $this->b = $units->create(UnitType::Section, 'B', '2/1/A/1-7', $this->platoon->id);
    $this->leaderA = User::factory()->commanding($this->a)->create();
    $this->leaderB = User::factory()->commanding($this->b)->create();

    // Placed by the sync before the week of 03OCT26, so the placement history has them in A all of that week.
    pastWeekAt('2026-09-30 12:00:00');
    $this->stayer = Trooper::factory()->create();
    $this->mover = Trooper::factory()->create();
    $troopers = app(TrooperRepository::class);
    $troopers->updatePrimarySections([$this->stayer->cav_id => $this->a->id, $this->mover->cav_id => $this->a->id]);
    $this->troopers = $troopers;
    $this->kpis = app(KpiService::class);
    $this->week = CarbonImmutable::parse('2026-10-03', 'UTC');

    // Wednesday of that week: A practises, the stayer is marked present.
    pastWeekAt('2026-10-07 20:00:00');
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->stayer->id, 'status' => 'present']]])->assertOk();
});

afterEach(fn () => Carbon::setTestNow());

it('keeps a trooper moved after the week on the rollcall, report and KPIs of the section he ended it in', function () {
    // Saturday 10OCT26 08:00, before A's report for 03OCT26 is due: the sync moves the mover to B.
    pastWeekAt('2026-10-10 08:00:00');
    $this->troopers->updatePrimarySections([$this->mover->cav_id => $this->b->id]);
    pastWeekAt('2026-10-10 12:00:00');

    $rollcallA = $this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-03")->assertOk();
    expect(pastWeekIds($rollcallA))->toContain($this->mover->id, $this->stayer->id)
        ->and(pastWeekRow($rollcallA, $this->mover->id)['placement'])->toBe('moved_out')
        ->and(pastWeekRow($rollcallA, $this->stayer->id)['placement'])->toBeNull();

    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'present']]])
        ->assertOk();

    // B never had him that week: not on its 03OCT26 rollcall, and it cannot mark him for it.
    expect(pastWeekIds($this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}?week=2026-10-03")))->not->toContain($this->mover->id);
    $this->actingAs($this->leaderB)->postJson("/rollcall/{$this->b->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'present']]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_placed');

    // The KPIs of the week agree with its rollcall: A counts him and his mark, B does not.
    $kpisA = $this->kpis->forUnit($this->a, $this->week);
    expect($kpisA->strength)->toBe(2)
        ->and($this->kpis->trooperAttendance($this->a, $this->week)[$this->mover->id]->practice)->toEqual(new Ratio(1, 1))
        ->and($this->kpis->forUnit($this->b, $this->week)->strength)->toBe(0)
        ->and($this->kpis->forSectionsWithin($this->platoon, $this->week)[$this->a->id]->strength)->toBe(2);

    // A's report for the week lists him, and takes a reason for him; B's does not.
    $report = $this->actingAs($this->leaderA)->get("/reports/{$this->a->id}/2026-10-03")->assertOk();
    expect(collect($report->viewData('page')['props']['troopers'])->pluck('id')->all())->toContain($this->mover->id);
    $this->actingAs($this->leaderA)->put("/reports/{$this->a->id}/2026-10-03", ['unaccounted' => [['trooper_id' => $this->mover->id, 'reason' => 'sick']]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->leaderB)->put("/reports/{$this->b->id}/2026-10-03", ['unaccounted' => [['trooper_id' => $this->mover->id, 'reason' => 'sick']]])
        ->assertSessionHasErrors();

    // The running week is who is placed now: he is B's (named: on this Saturday the bare URL opens the week still due).
    expect(pastWeekIds($this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}?week=2026-10-10")))->toContain($this->mover->id)
        ->and(pastWeekIds($this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-10")))->not->toContain($this->mover->id)
        ->and($this->kpis->forUnit($this->a, CarbonImmutable::parse('2026-10-10', 'UTC'))->strength)->toBe(1);

    // Once that week has ended too, he is on B's rollcall for it (where he ended it), noted as joined, and not on A's.
    pastWeekAt('2026-10-17 12:00:00');
    $rollcallB = $this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}?week=2026-10-10");
    expect(pastWeekIds($rollcallB))->toContain($this->mover->id)
        ->and(pastWeekRow($rollcallB, $this->mover->id)['placement'])->toBe('joined')
        ->and(pastWeekIds($this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-10")))->not->toContain($this->mover->id);
});

it('keeps a trooper who left the Cav during the week on that week, and off the weeks after it', function () {
    // Thursday 08OCT26: he drops off every Cav roster.
    pastWeekAt('2026-10-08 09:00:00');
    $this->troopers->markDepartedExcept([$this->stayer->cav_id], [$this->a->id]);
    expect($this->mover->refresh()->status)->toBe(TrooperStatus::Inactive)
        ->and($this->mover->departed_at?->toIso8601String())->toBe('2026-10-08T09:00:00+00:00');

    pastWeekAt('2026-10-10 12:00:00');
    $rollcall = $this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-03");
    expect(pastWeekIds($rollcall))->toContain($this->mover->id)
        ->and(pastWeekRow($rollcall, $this->mover->id)['placement'])->toBe('departed');
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'missed']]])
        ->assertOk();
    expect($this->kpis->forUnit($this->a, $this->week)->strength)->toBe(2);

    // The running week, and any week after he left, have no place for him.
    expect(pastWeekIds($this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-10")))->not->toContain($this->mover->id);
    pastWeekAt('2026-10-17 12:00:00');
    expect(pastWeekIds($this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-10")))->not->toContain($this->mover->id);
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-10', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'missed']]])
        ->assertStatus(422);
});

it('clears the departure date when a trooper comes back to a roster', function () {
    pastWeekAt('2026-10-08 09:00:00');
    $this->troopers->markDepartedExcept([$this->stayer->cav_id], [$this->a->id]);

    pastWeekAt('2026-10-09 09:00:00');
    $this->troopers->upsertActive([[
        'cav_id' => $this->mover->cav_id, 'rank' => null, 'mos' => null, 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value,
    ]]);

    expect($this->mover->refresh()->departed_at)->toBeNull();
});

it('keeps a trooper with no placement history in his current section for a past week', function () {
    // Placed around the history, as a seeder does: read as in his current section for every week.
    $untracked = Trooper::factory()->create();
    $untracked->forceFill(['primary_section_id' => $this->a->id])->save();

    pastWeekAt('2026-10-10 12:00:00');
    $rollcall = $this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}?week=2026-10-03");
    expect(pastWeekIds($rollcall))->toContain($untracked->id)
        ->and(pastWeekRow($rollcall, $untracked->id)['placement'])->toBeNull();
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $untracked->id, 'status' => 'present']]])
        ->assertOk();
    expect($this->kpis->forUnit($this->a, $this->week)->strength)->toBe(3);
});

it('leaves the running week on who is placed now', function () {
    // Saturday 03OCT26 is still running: the move lands mid-week and the rollcall follows it at once.
    pastWeekAt('2026-10-08 12:00:00');
    $this->troopers->updatePrimarySections([$this->mover->cav_id => $this->b->id]);

    $rollcallA = $this->actingAs($this->leaderA)->get("/rollcall/{$this->a->id}");
    expect(pastWeekIds($rollcallA))->toBe([$this->stayer->id])
        ->and(pastWeekRow($rollcallA, $this->stayer->id)['placement'])->toBeNull()
        ->and(pastWeekIds($this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}")))->toBe([$this->mover->id]);
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'present']]])
        ->assertStatus(422);
    $this->actingAs($this->leaderB)->postJson("/rollcall/{$this->b->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->mover->id, 'status' => 'present']]])
        ->assertOk();
    expect($this->kpis->forUnit($this->a, $this->week)->strength)->toBe(1)
        ->and($this->kpis->forUnit($this->b, $this->week)->strength)->toBe(1);
});
