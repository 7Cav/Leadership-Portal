<?php

use App\Commands\Attendance\RollcallWeekLockedException;
use App\Commands\Practices\ChangePracticeWeek;
use App\Commands\Practices\InvalidPracticeSlotException;
use App\Commands\Practices\PracticeSchedule;
use App\Commands\Practices\PracticeSlotForbiddenException;
use App\Commands\Practices\SetPracticeSlot;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// A section's weekly practice slot (#392): weekday and start in Zulu, always one hour, set by the section's leaders,
// the command above them and Admins, audited; one week's practice can be moved or cancelled on its own.

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-28 12:00:00'); // a Wednesday; the Cav week opened Saturday 24OCT26
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '4th Section', '4/1/B/2-7', $this->platoon->id);

    $this->admin = User::factory()->admin()->create();
    $this->sl = slotCommander($this->section, 7101);
    $this->pl = slotCommander($this->platoon, 7102);
    $this->siblingSl = slotCommander($this->sibling, 7103);
    $this->set = app(SetPracticeSlot::class);
    $this->change = app(ChangePracticeWeek::class);
    $this->schedule = app(PracticeSchedule::class);
    $this->week = CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function slotCommander(Unit $unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

it('lets the section leader set the slot, audited, and places it in the Cav week', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120); // Thu 0200Z

    $slot = SectionPracticeSlot::query()->sole();
    expect($slot->weekday)->toBe(4)->and($slot->start_minute)->toBe(120)
        ->and(AuditLogEntry::query()->where('action', 'practice_slot.set')->count())->toBe(1);

    $window = $this->schedule->windowFor($this->section->id, $this->week);
    expect($window->start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and($window->end->toIso8601ZuluString())->toBe('2026-10-29T03:00:00Z');
});

it('lets the command above and an Admin set it, and refuses a sibling leader and a staff grant', function () {
    $this->set->handle($this->pl, $this->section->id, 3, 1439);
    $this->set->handle($this->admin, $this->section->id, 4, 120);
    expect(SectionPracticeSlot::query()->sole()->weekday)->toBe(4);

    expect(fn () => $this->set->handle($this->siblingSl, $this->section->id, 1, 60))->toThrow(PracticeSlotForbiddenException::class);

    $staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section->id]);
    expect(fn () => $this->set->handle($staff, $this->section->id, 1, 60))->toThrow(PracticeSlotForbiddenException::class);
});

it('refuses a platoon and a slot out of range', function () {
    expect(fn () => $this->set->handle($this->admin, $this->platoon->id, 4, 120))->toThrow(InvalidPracticeSlotException::class)
        ->and(fn () => $this->set->handle($this->admin, $this->section->id, 7, 120))->toThrow(InvalidPracticeSlotException::class)
        ->and(fn () => $this->set->handle($this->admin, $this->section->id, 4, 1440))->toThrow(InvalidPracticeSlotException::class);
});

it('logs nothing for an unchanged slot, and clears it audited', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120);
    $this->set->handle($this->sl, $this->section->id, 4, 120);
    expect(AuditLogEntry::query()->where('action', 'practice_slot.set')->count())->toBe(1);

    $this->set->handle($this->sl, $this->section->id, null, null);
    expect(SectionPracticeSlot::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'practice_slot.cleared')->count())->toBe(1)
        ->and($this->schedule->windowFor($this->section->id, $this->week))->toBeNull();
});

it('lets sections share a slot', function () {
    $this->set->handle($this->pl, $this->section->id, 3, 1439);
    $this->set->handle($this->pl, $this->sibling->id, 3, 1439);

    expect($this->schedule->windowFor($this->section->id, $this->week)->start)
        ->toEqual($this->schedule->windowFor($this->sibling->id, $this->week)->start);
});

it('places a Wednesday 2359Z slot in its own week, ending on Thursday', function () {
    $this->set->handle($this->sl, $this->section->id, 3, 1439);
    $window = $this->schedule->windowFor($this->section->id, $this->week);

    expect($window->start->toIso8601ZuluString())->toBe('2026-10-28T23:59:00Z')
        ->and($window->end->toIso8601ZuluString())->toBe('2026-10-29T00:59:00Z');
});

it('moves and cancels one week only, audited, and puts it back', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120);

    $this->change->handle($this->sl, $this->section->id, $this->week, CarbonImmutable::parse('2026-10-30 01:00:00', 'UTC'), false);
    expect($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-30T01:00:00Z')
        // The next week is on the slot again.
        ->and($this->schedule->windowFor($this->section->id, $this->week->addWeek())->start->toIso8601ZuluString())->toBe('2026-11-05T02:00:00Z');

    $this->change->handle($this->sl, $this->section->id, $this->week, null, true);
    expect($this->schedule->windowFor($this->section->id, $this->week))->toBeNull();

    $this->change->handle($this->sl, $this->section->id, $this->week, null, false);
    expect($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and(AuditLogEntry::query()->where('action', 'practice_week.changed')->count())->toBe(3);
});

it('refuses a move outside the week, a section with no slot, a captured practice and a sent week', function () {
    expect(fn () => $this->change->handle($this->sl, $this->section->id, $this->week, null, true))->toThrow(InvalidPracticeSlotException::class);

    $this->set->handle($this->sl, $this->section->id, 4, 120);
    expect(fn () => $this->change->handle($this->sl, $this->section->id, $this->week, CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'), false))
        ->toThrow(InvalidPracticeSlotException::class);
    expect(fn () => $this->change->handle($this->siblingSl, $this->section->id, $this->week, null, true))->toThrow(PracticeSlotForbiddenException::class);

    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->week);
    app(SectionPracticeRepository::class)->stampCapture($practice, 'srv', now(), 'crcon', $this->week->addDays(5)->addHours(2), $this->week->addDays(5)->addHours(3));
    expect(fn () => $this->change->handle($this->sl, $this->section->id, $this->week, null, true))->toThrow(InvalidPracticeSlotException::class);

    // A slot set this week does not reach back to last week (#630), so last week gets its own.
    $previous = $this->week->subWeek();
    CarbonImmutable::setTestNow('2026-10-21 12:00:00');
    $this->set->handle($this->sl, $this->section->id, 3, 60);
    CarbonImmutable::setTestNow('2026-10-28 12:00:00');
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $previous]);
    expect(fn () => $this->change->handle($this->sl, $this->section->id, $previous, null, true))->toThrow(RollcallWeekLockedException::class);
});

it('keeps a captured week on the window it was read for when the slot changes', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->week);
    app(SectionPracticeRepository::class)->stampCapture($practice, 'srv', now(), 'crcon', CarbonImmutable::parse('2026-10-29 02:00:00', 'UTC'), CarbonImmutable::parse('2026-10-29 03:00:00', 'UTC'));

    $this->set->handle($this->sl, $this->section->id, 2, 60);

    expect(SectionPractice::query()->find($practice->id)->window_start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and($this->schedule->windowFor($this->section->id, $this->week->addWeek())->start->toIso8601ZuluString())->toBe('2026-11-03T01:00:00Z');
});

it('sets the slot over HTTP and answers the prop, and the roll call carries it', function () {
    $this->actingAs($this->sl)
        ->postJson("/rollcall/{$this->section->id}/slot", ['weekday' => 4, 'start_minute' => 120, 'week' => '2026-10-24'])
        ->assertOk()
        ->assertJsonPath('practiceSlot.weekday', 4)
        ->assertJsonPath('practiceSlot.week.start', '2026-10-29T02:00:00Z');

    $this->actingAs($this->sl)
        ->postJson("/rollcall/{$this->section->id}/slot/week", ['week' => '2026-10-24', 'cancelled' => true, 'moved_to' => null])
        ->assertOk()
        ->assertJsonPath('practiceSlot.week.cancelled', true);

    $this->actingAs($this->siblingSl)
        ->postJson("/rollcall/{$this->section->id}/slot", ['weekday' => 1, 'start_minute' => 0, 'week' => '2026-10-24'])
        ->assertForbidden();

    $this->actingAs($this->sl)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('practiceSlot.startMinute', 120)->where('practiceSlot.week.cancelled', true));
});

it('sets a first slot on a sent week from the next week, audited, and leaves the current week without one', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    expect($this->set->changesFrom($this->section->id)?->toDateString())->toBe('2026-10-31');
    $this->set->handle($this->sl, $this->section->id, 3, 1439); // Wed 2359Z

    $slot = SectionPracticeSlot::query()->sole();
    expect($slot->effective_from->toDateString())->toBe('2026-10-31')
        ->and($slot->previous_weekday)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'practice_slot.set')->count())->toBe(1)
        ->and($this->schedule->windowFor($this->section->id, $this->week))->toBeNull()
        ->and($this->schedule->windowFor($this->section->id, $this->week->addWeek())->start->toIso8601ZuluString())->toBe('2026-11-04T23:59:00Z')
        ->and($this->schedule->props($this->section->id, $this->week, null))->toMatchArray(['effectiveFrom' => '2026-10-31'])
        ->and($this->schedule->props($this->section->id, $this->week->addWeek(), null)['effectiveFrom'])->toBeNull();

    // The current week has no practice to move, and its report was sent.
    expect(fn () => $this->change->handle($this->sl, $this->section->id, $this->week, null, true))->toThrow(InvalidPracticeSlotException::class);
});

it('changes the slot on a sent week from the next week, the current week keeping the slot it had', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120); // Thu 0200Z, from this week
    expect(SectionPracticeSlot::query()->sole()->effective_from->toDateString())->toBe('2026-10-24');

    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);
    $this->set->handle($this->sl, $this->section->id, 1, 60); // Mon 0100Z
    $this->set->handle($this->sl, $this->section->id, 2, 60); // changed again the same week: still keeps Thu

    $slot = SectionPracticeSlot::query()->sole();
    expect([$slot->previous_weekday, $slot->previous_start_minute])->toBe([4, 120])
        ->and($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and($this->schedule->windowFor($this->section->id, $this->week->addWeek())->start->toIso8601ZuluString())->toBe('2026-11-03T01:00:00Z');
});

it('sets the slot over HTTP on a sent week and the roll call says from when', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    $this->actingAs($this->sl)
        ->postJson("/rollcall/{$this->section->id}/slot", ['weekday' => 3, 'start_minute' => 1439, 'week' => '2026-10-24'])
        ->assertOk()
        ->assertJsonPath('practiceSlot.effectiveFrom', '2026-10-31')
        ->assertJsonPath('practiceSlot.week.start', null);

    $this->actingAs($this->sl)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('practiceSlotChangesFrom', '2026-10-31')
            ->where('practiceSlot.effectiveFrom', '2026-10-31')
            ->where('practiceSlotMissing', false));
});

it('names the current week as the first a change affects when it is not held, the next when it is', function () {
    expect($this->set->changesFrom($this->section->id)->toDateString())->toBe('2026-10-24');

    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    expect($this->set->changesFrom($this->section->id)->toDateString())->toBe('2026-10-31');
});

it('keeps the slot a past week had after a later change and a removal', function () {
    CarbonImmutable::setTestNow('2026-10-14 12:00:00'); // week of 10OCT26
    $this->set->handle($this->sl, $this->section->id, 4, 120); // Thu 0200Z
    CarbonImmutable::setTestNow('2026-10-21 12:00:00'); // week of 17OCT26
    $this->set->handle($this->sl, $this->section->id, 1, 60); // Mon 0100Z
    CarbonImmutable::setTestNow('2026-10-28 12:00:00'); // week of 24OCT26
    $this->set->handle($this->sl, $this->section->id, 2, 60); // Tue 0100Z

    $start = fn (string $week) => $this->schedule->windowFor($this->section->id, CarbonImmutable::parse($week, 'UTC'))?->start->toIso8601ZuluString();
    expect($start('2026-10-03'))->toBeNull()
        ->and($start('2026-10-10'))->toBe('2026-10-15T02:00:00Z')
        ->and($start('2026-10-17'))->toBe('2026-10-19T01:00:00Z')
        ->and($start('2026-10-24'))->toBe('2026-10-27T01:00:00Z');

    $this->set->handle($this->sl, $this->section->id, null, null);

    expect($start('2026-10-10'))->toBe('2026-10-15T02:00:00Z')
        ->and($start('2026-10-17'))->toBe('2026-10-19T01:00:00Z')
        ->and($start('2026-10-24'))->toBeNull()
        ->and($this->schedule->props($this->section->id, CarbonImmutable::parse('2026-10-17', 'UTC'), null))->toMatchArray(['weekday' => 1, 'startMinute' => 60])
        ->and($this->schedule->props($this->section->id, $this->week, null))->toBeNull();
});

it('keeps a sent week on its slot when the slot is removed, removing it from the next week', function () {
    $this->set->handle($this->sl, $this->section->id, 4, 120);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

    $this->set->handle($this->sl, $this->section->id, null, null);

    expect($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and($this->schedule->windowFor($this->section->id, $this->week->addWeek()))->toBeNull();
});

it('records the slot a section had before its first recorded change', function () {
    // A slot from before the change history, with a pending start.
    SectionPracticeSlot::query()->create(['section_id' => $this->section->id, 'weekday' => 4, 'start_minute' => 120, 'effective_from' => '2026-10-24', 'previous_weekday' => 3, 'previous_start_minute' => 1439]);

    $this->set->handle($this->sl, $this->section->id, 1, 60);

    expect($this->schedule->windowFor($this->section->id, CarbonImmutable::parse('2026-10-17', 'UTC'))->start->toIso8601ZuluString())->toBe('2026-10-21T23:59:00Z')
        ->and($this->schedule->windowFor($this->section->id, $this->week)->start->toIso8601ZuluString())->toBe('2026-10-26T01:00:00Z');
});
