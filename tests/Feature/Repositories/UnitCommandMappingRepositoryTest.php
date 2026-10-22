<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Story 1.16 / AD-7: the leadership billet → commanded unit bridge, and the one joined lookup
// ScopeResolver uses to turn a signed-in user into the unit their roster billet commands.

function ucmLeader(int $cavId, int $positionId, TrooperStatus $status = TrooperStatus::Combat): User
{
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $positionId, 'status' => $status]);

    return User::factory()->create(['cav_id' => $cavId]);
}

it('lists every command mapping as position id => unit id', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);
    UnitCommandMapping::factory()->create(['unit_id' => $platoon->id, 'cav_position_id' => 199]);

    expect(app(UnitCommandMappingRepository::class)->unitIdsByPositionId()->all())
        ->toEqualCanonicalizing([196 => $company->id, 199 => $platoon->id]);
});

it('creates a mapping, one per billet', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    $repo = app(UnitCommandMappingRepository::class);

    $mapping = $repo->create($company->id, 196);

    expect($mapping->unit_id)->toBe($company->id)
        ->and($mapping->cav_position_id)->toBe(196);
    expect(fn () => $repo->create($company->id, 196))->toThrow(QueryException::class);
});

it('finds the unit a user\'s synced billet commands', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);

    $unit = app(UnitCommandMappingRepository::class)->commandedUnitFor(ucmLeader(42, 196));

    expect($unit?->id)->toBe($company->id)
        ->and($unit->path)->toBe($company->path);
});

it('finds nothing for a user with no cav_id, no synced trooper, or an unmapped billet', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);
    $repo = app(UnitCommandMappingRepository::class);

    // A trooper holding the mapped billet who is not this user must not leak to a cav_id-less one.
    Trooper::factory()->create(['cav_id' => 7, 'cav_position_id' => 196]);

    expect($repo->commandedUnitFor(User::factory()->create(['cav_id' => null])))->toBeNull()
        ->and($repo->commandedUnitFor(User::factory()->create(['cav_id' => 99])))->toBeNull()
        ->and($repo->commandedUnitFor(ucmLeader(43, 203)))->toBeNull();
});

it('finds the commanded unit for a trooper on any serving roster', function (TrooperStatus $status) {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);

    expect(app(UnitCommandMappingRepository::class)->commandedUnitFor(ucmLeader(42, 196, $status))?->id)->toBe($company->id);
})->with([TrooperStatus::Combat, TrooperStatus::Reserve, TrooperStatus::Eloa]);

it('repoints a billet and removes every billet not kept', function () {
    $a = Unit::factory()->create(['type' => UnitType::Company]);
    $b = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $a->id, 'cav_position_id' => 196]);
    UnitCommandMapping::factory()->create(['unit_id' => $a->id, 'cav_position_id' => 197]);
    UnitCommandMapping::factory()->create(['unit_id' => $a->id, 'cav_position_id' => 198]);
    $repo = app(UnitCommandMappingRepository::class);

    $repo->repoint(196, $b->id);
    $removed = $repo->deleteExcept([196, 197]);

    expect($removed)->toBe(1)
        ->and($repo->unitIdsByPositionId()->all())->toEqualCanonicalizing([196 => $b->id, 197 => $a->id]);
});

it('finds nothing for a departed trooper even though their billet is still mapped', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);

    expect(app(UnitCommandMappingRepository::class)->commandedUnitFor(ucmLeader(42, 196, TrooperStatus::Inactive)))->toBeNull();
});

it('drops a command mapping with its unit, so a deleted unit confers nothing', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);
    $user = ucmLeader(42, 196);

    $company->delete();

    expect(UnitCommandMapping::count())->toBe(0)
        ->and(app(UnitCommandMappingRepository::class)->commandedUnitFor($user))->toBeNull();
});

it('resolves the commanded unit in a single query', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company]);
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 196]);
    $user = ucmLeader(42, 196);

    DB::enableQueryLog();
    app(UnitCommandMappingRepository::class)->commandedUnitFor($user);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('names the serving trooper holding each unit\'s command billet, keyed by unit (2.8)', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $vacant = Unit::factory()->create(['type' => UnitType::Section]);
    UnitCommandMapping::factory()->create(['unit_id' => $platoon->id, 'cav_position_id' => 199]);
    UnitCommandMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => 201]);
    UnitCommandMapping::factory()->create(['unit_id' => $vacant->id, 'cav_position_id' => 202]);
    $pl = Trooper::factory()->create(['cav_position_id' => 199]);
    $sl = Trooper::factory()->create(['cav_position_id' => 201]);
    Trooper::factory()->create(['cav_position_id' => 202, 'status' => TrooperStatus::Inactive]);

    $leaders = app(UnitCommandMappingRepository::class)->commandersOf([$platoon->id, $section->id, $vacant->id]);

    expect($leaders->keys()->sort()->values()->all())->toBe(collect([$platoon->id, $section->id])->sort()->values()->all())
        ->and($leaders[$platoon->id]->is($pl))->toBeTrue()
        ->and($leaders[$section->id]->is($sl))->toBeTrue()
        ->and(app(UnitCommandMappingRepository::class)->commandersOf([])->all())->toBe([]);
});

it('names the Section Leader rather than the assistant whose billet maps to the same section, whoever synced first', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $other = Unit::factory()->create(['type' => UnitType::Section]);
    foreach ([[$section, 201], [$section, 202], [$other, 301], [$other, 302]] as [$unit, $positionId]) {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    }
    // Synced in either order: the assistant before the leader in one section, after in the other.
    Trooper::factory()->create(['cav_position_id' => 202, 'billet' => 'Assistant Section Leader 1/1/A/1-7']);
    $sl = Trooper::factory()->create(['cav_position_id' => 201, 'billet' => 'Section Leader 1/1/A/1-7']);
    $otherSl = Trooper::factory()->create(['cav_position_id' => 301, 'billet' => 'Section Leader 2/1/A/1-7']);
    Trooper::factory()->create(['cav_position_id' => 302, 'billet' => 'Assistant Section Leader 2/1/A/1-7']);

    $leaders = app(UnitCommandMappingRepository::class)->commandersOf([$section->id, $other->id]);

    expect($leaders[$section->id]->is($sl))->toBeTrue()
        ->and($leaders[$other->id]->is($otherSl))->toBeTrue();
});

it('names the assistant while the Section Leader billet is vacant', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    UnitCommandMapping::factory()->create(['unit_id' => $section->id, 'cav_position_id' => 202]);
    $asl = Trooper::factory()->create(['cav_position_id' => 202, 'billet' => 'Assistant Section Leader 1/1/A/1-7']);

    expect(app(UnitCommandMappingRepository::class)->commandersOf([$section->id])[$section->id]->is($asl))->toBeTrue();
});
