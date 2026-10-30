<?php

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// A recruit who joined after a week has ended is not on that week's roll call. A trooper with no placement history is
// read as placed in their current section every week, so the join date (`tis`) is what keeps them off the older weeks.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $reg = $units->create(UnitType::Regiment, 'Reg', null, null);
    $bn = $units->create(UnitType::Battalion, 'Bn', '1-7', $reg->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $bn->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, 'A', '1/1/A/1-7', $platoon->id);
    $this->leader = User::factory()->commanding($this->section)->create();

    // Saturday 24Oct26: the veteran joined years ago, the recruit on 20Oct26. Neither has placement history rows.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
    $this->veteran = Trooper::factory()->create(['tis' => '2015-03-01']);
    $this->recruit = Trooper::factory()->create(['tis' => '2026-10-20']);
    $this->undated = Trooper::factory()->create(['tis' => null]);
    Trooper::query()->whereKey([$this->veteran->id, $this->recruit->id, $this->undated->id])->update(['primary_section_id' => $this->section->id]);
});

afterEach(fn () => Carbon::setTestNow());

function joinersOnRollcall(Unit $section, string $week): array
{
    return app(TrooperRepository::class)->onRollcall($section, CarbonImmutable::parse($week, 'UTC'))->troopers->pluck('id')->sort()->values()->all();
}

it('leaves a recruit off the roll calls of weeks that ended before they joined', function () {
    expect(joinersOnRollcall($this->section, '2026-10-03'))->toBe([$this->veteran->id, $this->undated->id])
        // The week they joined in, and the running week, list them.
        ->and(joinersOnRollcall($this->section, '2026-10-17'))->toBe([$this->veteran->id, $this->recruit->id, $this->undated->id])
        ->and(joinersOnRollcall($this->section, '2026-10-24'))->toBe([$this->veteran->id, $this->recruit->id, $this->undated->id]);
});

it('shows a past week\'s roll call page without the later recruit', function () {
    $ids = collect($this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-03")->assertOk()->viewData('page')['props']['troopers'])
        ->pluck('id')->sort()->values()->all();

    expect($ids)->toBe([$this->veteran->id, $this->undated->id]);
});

it('refuses a mark for a recruit on a week before they joined, and counts only who was there', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->recruit->id, 'status' => 'present']]])
        ->assertStatus(422);

    $repo = app(TrooperRepository::class);
    $week = CarbonImmutable::parse('2026-10-03', 'UTC');

    expect($repo->idsOnRollcall($this->section->id, $week, [$this->veteran->id, $this->recruit->id])->all())->toBe([$this->veteran->id])
        ->and(array_keys($repo->onRollcalls([$this->section->id], $week)->sections))->not->toContain($this->recruit->id);
});
