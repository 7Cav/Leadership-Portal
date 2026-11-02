<?php

use App\Commands\Units\CreateUnit;
use App\Commands\Units\InvalidUnitOrdinalException;
use App\Commands\Units\InvalidUnitPlacementException;
use App\Models\AuditLogEntry;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

function cuTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '3rd Battalion', '3-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/3-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/3-7', $company->id);
    $repo->create(UnitType::Section, '1st Section', '1/1/A/3-7', $platoon->id);
    $repo->create(UnitType::Section, '3rd Section', '3/1/A/3-7', $platoon->id);

    return compact('regiment', 'battalion', 'company', 'platoon');
}

it('delegates to the repository so the write and its path computation stay one transaction', function () {
    $actor = User::factory()->admin()->create();

    $unit = app(CreateUnit::class)->handle($actor, UnitType::Regiment, null);

    expect($unit->name)->toBe('7th Cavalry Regiment')
        ->and($unit->callsign)->toBe('7CAV')
        ->and($unit->path)->toBe("{$unit->id}/");
});

it('records exactly one audit_log entry for the created unit', function () {
    $actor = User::factory()->admin()->create();

    $unit = app(CreateUnit::class)->handle($actor, UnitType::Regiment, null);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.created')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unit->id)
        ->and($entry->reason)->toBeNull()
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('derives the name and callsign of each echelon from its parent and the picked ordinal', function (string $parent, UnitType $type, string $ordinal, string $name, string $callsign) {
    $actor = User::factory()->admin()->create();
    $tree = cuTree();

    $unit = app(CreateUnit::class)->handle($actor, $type, $tree[$parent]->id, $ordinal);

    expect($unit->name)->toBe($name)
        ->and($unit->callsign)->toBe($callsign)
        ->and($unit->parent_id)->toBe($tree[$parent]->id);
})->with([
    'battalion under the regiment' => ['regiment', UnitType::Battalion, '2', '2nd Battalion', '2-7'],
    'company under a battalion' => ['battalion', UnitType::Company, 'B', 'Bravo Company', 'B/3-7'],
    'lower-case letter' => ['battalion', UnitType::Company, 'c', 'Charlie Company', 'C/3-7'],
    'platoon under a company' => ['company', UnitType::Platoon, '2', '2nd Platoon', '2/A/3-7'],
    'section in a gap' => ['platoon', UnitType::Section, '2', '2nd Section', '2/1/A/3-7'],
]);

it('names a company the import names differently the same way, such as Baker Company of the ACD', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $acd = $repo->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', $regiment->id);

    $unit = app(CreateUnit::class)->handle($actor, UnitType::Company, $acd->id, 'B');

    expect($unit->name)->toBe('Baker Company')->and($unit->callsign)->toBe('B/ACD');
});

it('refuses an ordinal already used under the same parent, and writes nothing', function () {
    $actor = User::factory()->admin()->create();
    $tree = cuTree();

    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Section, $tree['platoon']->id, '3'))
        ->toThrow(InvalidUnitOrdinalException::class, '3rd Section already exists here. Pick another.');

    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses an ordinal out of range or of the wrong kind', function (UnitType $type, string $parent, string $ordinal) {
    $actor = User::factory()->admin()->create();
    $tree = cuTree();

    expect(fn () => app(CreateUnit::class)->handle($actor, $type, $tree[$parent]->id, $ordinal))
        ->toThrow(InvalidUnitOrdinalException::class);
})->with([
    'number for a company' => [UnitType::Company, 'battalion', '2'],
    'letter for a section' => [UnitType::Section, 'platoon', 'B'],
    'past the last' => [UnitType::Platoon, 'company', '10'],
    'none' => [UnitType::Battalion, 'regiment', ''],
]);

it('refuses a line unit under a parent with no callsign to build on', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, 'Unnamed', null, $regiment->id);

    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Company, $battalion->id, 'A'))
        ->toThrow(InvalidUnitOrdinalException::class, 'The parent unit has no callsign to build this one from.');
});

it('takes a free name and no callsign for a group only', function () {
    $actor = User::factory()->admin()->create();
    $tree = cuTree();

    $group = app(CreateUnit::class)->handle($actor, UnitType::Group, $tree['regiment']->id, null, 'S6 Department');

    expect($group->name)->toBe('S6 Department')
        ->and($group->callsign)->toBeNull()
        ->and($group->kind)->toBe(UnitKind::Staff);

    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Group, $tree['regiment']->id, null, '  '))
        ->toThrow(InvalidUnitOrdinalException::class, 'Give the group a name.');
});

it('refuses a non-Regiment root node', function () {
    $actor = User::factory()->admin()->create();

    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Battalion, null, '2'))
        ->toThrow(InvalidUnitPlacementException::class, 'A unit with no parent must be a regiment, not a battalion.');

    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses a child that is not exactly one rank below its parent', function () {
    $actor = User::factory()->admin()->create();
    $tree = cuTree();

    // Skips a rank (Regiment -> Company, should be Regiment -> Battalion).
    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Company, $tree['regiment']->id, 'E'))
        ->toThrow(InvalidUnitPlacementException::class, 'A company cannot be placed under a regiment: it must sit exactly one level below its parent.');

    // A battalion cannot be placed under a battalion.
    expect(fn () => app(CreateUnit::class)->handle($actor, UnitType::Battalion, $tree['battalion']->id, '1'))
        ->toThrow(InvalidUnitPlacementException::class);

    expect(AuditLogEntry::count())->toBe(0);
});
