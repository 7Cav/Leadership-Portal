<?php

use App\Commands\Practices\ConfirmClockChange;
use App\Commands\Practices\SetPracticeSlot;
use App\Commands\WeeklyReports\ClockChangeNotConfirmedException;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Models\AuditLogEntry;
use App\Models\SectionPracticeSlot;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// 16NOV26: a slot is kept in Zulu, so when clocks change where the platoon leader lives the practice moves an hour on
// their clock. In the week before the change and the week of it, the platoon leader keeps or moves each section's
// slot, and the section's weekly report waits for the answer.

function clockCommander(Unit $unit, int $positionId, array $user = []): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId, 'status' => 'combat']);

    return User::factory()->create(['cav_id' => $trooper->cav_id, ...$user]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00:00'); // Wednesday of the Cav week 24OCT26; US clocks go back 01NOV26
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $this->platoon->id);
    $this->sl = clockCommander($this->section, 7201);
    $this->pl = clockCommander($this->platoon, 7202, ['timezone' => 'America/New_York']);
    app(SetPracticeSlot::class)->handle($this->sl, $this->section->id, 4, 120); // Thu 0200Z
    $this->clock = app(ConfirmClockChange::class);
});

it('asks in the week before the change and the week of it, in the platoon leader\'s time zone', function () {
    $before = $this->clock->notice($this->section, CarbonImmutable::parse('2026-10-24'));
    $of = $this->clock->notice($this->section, CarbonImmutable::parse('2026-10-31'));

    expect($before)->toMatchArray(['at' => '2026-11-01T06:00:00Z', 'phase' => 'before', 'timezone' => 'America/New_York', 'slot' => [4, 120], 'keepLocal' => [4, 180], 'confirmed' => null])
        ->and($of['phase'])->toBe('of')
        ->and($this->clock->notice($this->section, CarbonImmutable::parse('2026-11-07')))->toBeNull();
});

it('asks nothing when the platoon leader\'s time zone is not known yet', function () {
    $this->pl->forceFill(['timezone' => null])->save();

    expect($this->clock->notice($this->section, CarbonImmutable::parse('2026-10-24')))->toBeNull();
});

it('lets the platoon leader move the slot from the first practice after the change, once, audited', function () {
    $this->actingAs($this->pl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-10-24', 'move' => true])
        ->assertOk()
        ->assertJsonPath('clockChange.confirmed.moved', true)
        ->assertJsonPath('clockChange.confirmed.slot', [4, 180]);

    $slot = SectionPracticeSlot::query()->sole();
    expect($slot->placingFor(CarbonImmutable::parse('2026-10-24')))->toBe([4, 120])
        ->and($slot->placingFor(CarbonImmutable::parse('2026-10-31')))->toBe([4, 180])
        ->and(AuditLogEntry::query()->where('action', 'practice_slot.clock_change_confirmed')->count())->toBe(1);

    // Answered: a second answer changes nothing, and the week of the change asks no more.
    $this->actingAs($this->pl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-10-24', 'move' => false])->assertOk();
    expect(AuditLogEntry::query()->where('action', 'practice_slot.clock_change_confirmed')->count())->toBe(1);
});

it('keeps the slot as it is when the platoon leader says so', function () {
    $this->actingAs($this->pl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-10-24', 'move' => false])
        ->assertOk()->assertJsonPath('clockChange.confirmed.moved', false);

    expect(SectionPracticeSlot::query()->sole()->placingFor(CarbonImmutable::parse('2026-10-31')))->toBe([4, 120]);
});

it('refuses the section leader, who may not change the platoon, and a week with no change', function () {
    $this->actingAs($this->sl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-10-24', 'move' => true])->assertForbidden();
    $this->actingAs($this->pl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-11-07', 'move' => true])->assertStatus(422);
});

it('holds the section\'s report until the platoon leader answers', function () {
    $send = fn () => app(SendWeeklyReportHandler::class)->handle($this->sl, new SendWeeklyReport($this->section->id, CarbonImmutable::parse('2026-10-24'), 'sent for the test', null));

    expect($send)->toThrow(ClockChangeNotConfirmedException::class);

    $this->actingAs($this->pl)->postJson("/rollcall/{$this->section->id}/slot/clock-change", ['week' => '2026-10-24', 'move' => false])->assertOk();

    expect($this->clock->notice($this->section, CarbonImmutable::parse('2026-10-24'))['confirmed'])->not->toBeNull();
});

it('shows the change on the roll call, with who may answer', function () {
    $this->actingAs($this->sl)->get("/rollcall/{$this->section->id}?week=2026-10-24")
        ->assertInertia(fn ($page) => $page->where('clockChange.phase', 'before')->where('clockChange.canConfirm', false));
    $this->actingAs($this->pl)->get("/rollcall/{$this->section->id}?week=2026-10-24")
        ->assertInertia(fn ($page) => $page->where('clockChange.canConfirm', true));
});

it('lists the section on the platoon leader\'s Home until answered', function () {
    // Home lists units with troopers in them.
    Trooper::factory()->create(['status' => 'combat'])->forceFill(['primary_section_id' => $this->section->id])->save();

    $this->actingAs($this->pl)->get('/home')->assertInertia(fn ($page) => $page->where('attention.totals.clockChange', 1));
});

it('keeps the time zone the browser reports, and refuses one that does not exist', function () {
    $this->actingAs($this->sl)->postJson('/me/timezone', ['timezone' => 'Europe/Amsterdam'])->assertOk();
    $this->actingAs($this->sl)->postJson('/me/timezone', ['timezone' => 'Mars/Olympus'])->assertStatus(422);

    expect($this->sl->fresh()->timezone)->toBe('Europe/Amsterdam');
});
