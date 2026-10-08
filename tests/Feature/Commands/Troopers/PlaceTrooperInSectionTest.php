<?php

use App\Commands\Troopers\InvalidTrooperPlacementException;
use App\Commands\Troopers\PlaceTrooperForbiddenException;
use App\Commands\Troopers\PlaceTrooperInSection;
use App\Commands\Troopers\PlaceTrooperInSectionHandler;
use App\Models\AuditLogEntry;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\TrooperRepository\Eloquent as TrooperEloquent;
use App\Support\Audit\ReasonRequiredException;

// Story 2.7: an Admin places a trooper the roster sync could not map, through the audited envelope.

function placeTrooper(User $actor, Trooper $trooper, Unit $section, string $reason = 'Transferred from 3rd Platoon, billet not on the Cav roster yet.'): Trooper
{
    return app(PlaceTrooperInSectionHandler::class)->handle($actor, new PlaceTrooperInSection($trooper->id, $section->id, $reason));
}

function placementSection(): Unit
{
    return Unit::factory()->create(['type' => UnitType::Section]);
}

it('places an unplaced trooper into the section, marked as a manual placement', function () {
    $admin = User::factory()->admin()->create();
    $section = placementSection();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $placed = placeTrooper($admin, $trooper, $section);

    expect($placed->primary_section_id)->toBe($section->id)
        ->and($placed->placement_source)->toBe(PlacementSource::Manual)
        ->and($trooper->fresh()->primary_section_id)->toBe($section->id);
});

it('writes only the placement columns, never a synced roster field', function () {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create([
        'primary_section_id' => null, 'username' => 'Hollis.D', 'rank' => 'Private', 'mos' => '11B',
        'cav_position_id' => 412, 'cav_billet' => 'Rifleman', 'status' => TrooperStatus::Reserve,
    ]);
    $before = $trooper->fresh()->only(['cav_id', 'username', 'rank', 'mos', 'tis', 'tig', 'status', 'cav_position_id', 'cav_billet']);

    placeTrooper($admin, $trooper, placementSection());

    expect($trooper->fresh()->only(['cav_id', 'username', 'rank', 'mos', 'tis', 'tig', 'status', 'cav_position_id', 'cav_billet']))->toEqual($before);
});

it('puts the trooper in that section\'s roster and leaves the unplaced list', function () {
    $admin = User::factory()->admin()->create();
    $section = placementSection();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);
    $repo = app(TrooperRepository::class);
    expect($repo->unplaced())->toHaveCount(1);

    placeTrooper($admin, $trooper, $section);

    expect($repo->unplaced())->toHaveCount(0)
        ->and($repo->assignedWithin($section)->all())->toBe([$trooper->id]);
});

it('records one audit_log entry with the actor, the reason and the trooper as the target', function () {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    placeTrooper($admin, $trooper, placementSection(), 'Verified with the section leader.');

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->action)->toBe('trooper.placed')
        ->and($entry->target_type)->toBe('Trooper')
        ->and($entry->target_id)->toBe((string) $trooper->id)
        ->and($entry->reason)->toBe('Verified with the section leader.')
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('rejects a blank reason and writes nothing', function (string $reason) {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    expect(fn () => placeTrooper($admin, $trooper, placementSection(), $reason))->toThrow(ReasonRequiredException::class);

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   ']]);

it('refuses anyone who is not an Admin, however much scope they hold', function () {
    $leader = User::factory()->create();
    $section = placementSection();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    expect(fn () => placeTrooper($leader, $trooper, $section))->toThrow(PlaceTrooperForbiddenException::class);

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a unit that is not a section', function (UnitType $type) {
    $admin = User::factory()->admin()->create();
    $unit = Unit::factory()->create(['type' => $type]);
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    expect(fn () => placeTrooper($admin, $trooper, $unit))->toThrow(InvalidTrooperPlacementException::class, 'not a section');

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
})->with([UnitType::Regiment, UnitType::Battalion, UnitType::Company, UnitType::Platoon]);

it('refuses a trooper who is already placed, leaving the existing placement alone', function () {
    $admin = User::factory()->admin()->create();
    $current = placementSection();
    $trooper = Trooper::factory()->create(['primary_section_id' => $current->id, 'placement_source' => PlacementSource::Sync]);

    expect(fn () => placeTrooper($admin, $trooper, placementSection()))->toThrow(InvalidTrooperPlacementException::class, 'already placed');

    $fresh = $trooper->fresh();
    expect($fresh->primary_section_id)->toBe($current->id)
        ->and($fresh->placement_source)->toBe(PlacementSource::Sync)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a trooper who has left the Cav roster', function () {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Inactive]);

    expect(fn () => placeTrooper($admin, $trooper, placementSection()))->toThrow(InvalidTrooperPlacementException::class, 'no longer on a Cav roster');

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a trooper id that does not exist', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(PlaceTrooperInSectionHandler::class)->handle($admin, new PlaceTrooperInSection(999999, placementSection()->id, 'Because.')))
        ->toThrow(InvalidTrooperPlacementException::class, 'no trooper');

    expect(AuditLogEntry::count())->toBe(0);
});

it('rolls the audit entry back with the placement when the trooper was placed in the meantime', function () {
    $admin = User::factory()->admin()->create();
    $section = placementSection();
    $other = placementSection();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    // A sync places the trooper just after the handler read them: `find` hands back the unplaced
    // row, but by the time the conditional write runs the database has them placed.
    app()->instance(TrooperRepository::class, new class($other->id) extends TrooperEloquent
    {
        public function __construct(private readonly int $otherSectionId) {}

        public function find(int $id): ?Trooper
        {
            $trooper = parent::find($id);
            $this->updatePrimarySections([$trooper->cav_id => $this->otherSectionId]);

            return $trooper;
        }
    });

    expect(fn () => placeTrooper($admin, $trooper, $section))->toThrow(InvalidTrooperPlacementException::class, 'while this was submitted');

    expect($trooper->fresh()->primary_section_id)->toBe($other->id)
        ->and($trooper->fresh()->placement_source)->toBe(PlacementSource::Sync)
        ->and(AuditLogEntry::count())->toBe(0);
});
