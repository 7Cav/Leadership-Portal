<?php

use App\Commands\Templates\CarryUnitTemplates;
use App\Commands\Units\ClashResolution;
use App\Commands\Units\InvalidUnitOrdinalException;
use App\Commands\Units\MoveUnit;
use App\Commands\Units\UnitDesignationClashException;
use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// A designation always follows the parent (2026-11-02): a moved unit rebuilds its callsign from the new parent, and so
// does everything under it. A clash under the new parent is refused unless the move says how to settle it.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $this->alpha = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $this->golf = $repo->create(UnitType::Company, 'Golf Company', 'G/1-7', $battalion->id);
    $this->hotel = $repo->create(UnitType::Company, 'Hotel Company', 'H/1-7', $battalion->id);
    $this->moved = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->movedFirst = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->moved->id);
    $this->movedSecond = $repo->create(UnitType::Section, 'Wardogs', '2/1/A/1-7', $this->moved->id);
    $this->existing = $repo->create(UnitType::Platoon, '1st Platoon', '1/G/1-7', $this->golf->id);
    $this->existingFirst = $repo->create(UnitType::Section, '1st Section', '1/1/G/1-7', $this->existing->id);
    $repo->create(UnitType::Platoon, '2nd Platoon', '2/G/1-7', $this->golf->id);
});

$callsign = fn (Unit $unit) => Unit::find($unit->id)->callsign;

it('rebuilds the moved unit and everything under it from the new parent', function () use ($callsign) {
    $moved = app(MoveUnit::class)->handle($this->admin, $this->moved, $this->hotel->id);

    expect($moved->callsign)->toBe('1/H/1-7')
        ->and($moved->name)->toBe('1st Platoon')
        ->and($callsign($this->movedFirst))->toBe('1/1/H/1-7')
        ->and($callsign($this->movedSecond))->toBe('2/1/H/1-7')
        // A name an Admin chose is kept; only a derived one follows.
        ->and(Unit::find($this->movedSecond->id)->name)->toBe('Wardogs');
});

it('lists every designation before and after in the one audit entry', function () {
    app(MoveUnit::class)->handle($this->admin, $this->moved, $this->hotel->id);

    $entry = AuditLogEntry::where('action', 'unit.moved')->sole();
    $changes = collect($entry->details['changes']);

    expect($entry->action)->toBe('unit.moved')
        ->and($changes)->toContain(['field' => 'Callsign', 'from' => '1/A/1-7', 'to' => '1/H/1-7'])
        ->and($changes->pluck('from')->all())->toContain('1/1/A/1-7', '2/1/A/1-7')
        ->and($changes->pluck('to')->all())->toContain('1/1/H/1-7', '2/1/H/1-7');
});

it('refuses a move whose designation is already held under the new parent, and writes nothing', function () use ($callsign) {
    expect(fn () => app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id))
        ->toThrow(UnitDesignationClashException::class, '1/G/1-7 already exists under G/1-7.');

    expect(Unit::find($this->moved->id)->parent_id)->toBe($this->alpha->id)
        ->and($callsign($this->moved))->toBe('1/A/1-7')
        ->and(AuditLogEntry::count())->toBe(0);
});

it('swaps: the two trade places and both subtrees follow their new parents', function () use ($callsign) {
    app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, ClashResolution::Swap);

    expect(Unit::find($this->moved->id)->parent_id)->toBe($this->golf->id)
        ->and($callsign($this->moved))->toBe('1/G/1-7')
        ->and($callsign($this->movedFirst))->toBe('1/1/G/1-7')
        ->and(Unit::find($this->existing->id)->parent_id)->toBe($this->alpha->id)
        ->and($callsign($this->existing))->toBe('1/A/1-7')
        ->and($callsign($this->existingFirst))->toBe('1/1/A/1-7')
        ->and(AuditLogEntry::where('action', 'unit.moved')->count())->toBe(1);
});

it('refuses a swap when the old callsign is held under the old parent too', function () use ($callsign) {
    app(UnitRepository::class)->create(UnitType::Platoon, 'Duplicate', '1/A/1-7', $this->alpha->id);

    expect(fn () => app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, ClashResolution::Swap))
        ->toThrow(UnitDesignationClashException::class, 'cannot swap');

    expect($callsign($this->existing))->toBe('1/G/1-7')->and(AuditLogEntry::count())->toBe(0);
});

it('gives the unit already there another free number, renaming a derived name', function () use ($callsign) {
    app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, ClashResolution::RenumberExisting, '3');

    expect($callsign($this->moved))->toBe('1/G/1-7')
        ->and($callsign($this->existing))->toBe('3/G/1-7')
        ->and(Unit::find($this->existing->id)->name)->toBe('3rd Platoon')
        ->and($callsign($this->existingFirst))->toBe('1/3/G/1-7');
});

it('gives the moved unit another free number instead', function () use ($callsign) {
    app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, ClashResolution::RenumberMoved, '4');

    expect($callsign($this->moved))->toBe('4/G/1-7')
        ->and(Unit::find($this->moved->id)->name)->toBe('4th Platoon')
        ->and($callsign($this->movedFirst))->toBe('1/4/G/1-7')
        ->and($callsign($this->existing))->toBe('1/G/1-7');
});

it('refuses a number that is taken, for either renumber, and writes nothing', function (ClashResolution $resolution, string $ordinal) use ($callsign) {
    expect(fn () => app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, $resolution, $ordinal))
        ->toThrow(InvalidUnitOrdinalException::class);

    expect($callsign($this->moved))->toBe('1/A/1-7')
        ->and($callsign($this->existing))->toBe('1/G/1-7')
        ->and(AuditLogEntry::count())->toBe(0);
})->with([
    'existing takes the moved one\'s' => [ClashResolution::RenumberExisting, '1'],
    'existing takes a sibling\'s' => [ClashResolution::RenumberExisting, '2'],
    'moved takes a sibling\'s' => [ClashResolution::RenumberMoved, '2'],
]);

it('refuses to swap or renumber when the clash is gone by the time it saves', function () {
    expect(fn () => app(MoveUnit::class)->handle($this->admin, $this->moved, $this->hotel->id, ClashResolution::Swap))
        ->toThrow(UnitDesignationClashException::class, 'Nothing holds 1/H/1-7 under H/1-7 any more.');
});

it('rolls back every renumber when a later step of the move fails', function () use ($callsign) {
    $this->mock(CarryUnitTemplates::class, function ($mock) {
        $mock->shouldReceive('usedBy')->andReturn([null, []]);
        $mock->shouldReceive('afterMove')->andThrow(new RuntimeException('boom'));
    });

    expect(fn () => app(MoveUnit::class)->handle($this->admin, $this->moved, $this->golf->id, ClashResolution::Swap))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Unit::find($this->moved->id)->parent_id)->toBe($this->alpha->id)
        ->and($callsign($this->moved))->toBe('1/A/1-7')
        ->and($callsign($this->movedFirst))->toBe('1/1/A/1-7')
        ->and(Unit::find($this->existing->id)->parent_id)->toBe($this->golf->id)
        ->and($callsign($this->existing))->toBe('1/G/1-7')
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses to swap or renumber a unit outside the actor\'s write scope', function (ClashResolution $resolution) use ($callsign) {
    $outsider = User::factory()->serving()->create();

    expect(fn () => app(MoveUnit::class)->handle($outsider, $this->moved, $this->golf->id, $resolution, '3'))
        ->toThrow(UnitDesignationClashException::class, 'You may not renumber 1/G/1-7: it is outside your write scope.');

    expect($callsign($this->existing))->toBe('1/G/1-7')->and(AuditLogEntry::count())->toBe(0);
})->with([ClashResolution::Swap, ClashResolution::RenumberExisting]);

it('keeps the callsigns of a unit with no designation to rebuild', function () {
    $repo = app(UnitRepository::class);
    $acd = $repo->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', Unit::where('type', 'regiment')->value('id'));
    $platoon = $repo->create(UnitType::Platoon, 'Odd', 'Odd one', $this->alpha->id);

    $moved = app(MoveUnit::class)->handle($this->admin, $platoon, $this->golf->id);

    expect($moved->callsign)->toBe('Odd one')->and($acd->callsign)->toBe('ACD');
});

it('settles a clash through the edit form and keeps the rebuilt designation over the resent old one', function () use ($callsign) {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->moved->id}", [
        'name' => '1st Platoon',
        'callsign' => '1/A/1-7',
        'parent_id' => $this->golf->id,
    ])->assertSessionHasErrors(['clash' => '1/G/1-7 already exists under G/1-7. Choose how to settle it.']);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->moved->id}", [
        'name' => '1st Platoon',
        'callsign' => '1/A/1-7',
        'parent_id' => $this->golf->id,
        'clash' => 'renumber_moved',
        'clash_ordinal' => '2',
    ])->assertSessionHasErrors(['clash_ordinal']);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->moved->id}", [
        'name' => '1st Platoon',
        'callsign' => '1/A/1-7',
        'parent_id' => $this->golf->id,
        'clash' => 'renumber_existing',
        'clash_ordinal' => '5',
    ])->assertSessionHasNoErrors();

    expect($callsign($this->moved))->toBe('1/G/1-7')
        ->and($callsign($this->existing))->toBe('5/G/1-7')
        ->and(AuditLogEntry::where('action', 'unit.renamed')->count())->toBe(0);
});
