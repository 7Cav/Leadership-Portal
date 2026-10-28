<?php

use App\Commands\Units\DeleteUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\TrainingPlanRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('deletes a leaf unit', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    app(DeleteUnit::class)->handle($actor, $unit);

    expect(Unit::find($unit->id))->toBeNull();
});

it('records exactly one audit_log entry for the delete', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $unit = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    $unitId = $unit->id;

    app(DeleteUnit::class)->handle($actor, $unit);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('unit.deleted')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $unitId);
});

it('refuses to delete a unit that still has children', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    expect(fn () => app(DeleteUnit::class)->handle($actor, $regiment))
        ->toThrow(UnitHasDependentsException::class, 'Cannot delete "7th Cavalry Regiment" — it still has child units. Re-parent or remove them first.');

    expect(Unit::find($regiment->id))->not->toBeNull();
    expect(AuditLogEntry::count())->toBe(0);
});

it('refuses to delete a section Cav billets still map to, with a reason instead of a constraint error (2.9)', function () {
    $actor = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    UnitBilletMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => 201]);

    expect(fn () => app(DeleteUnit::class)->handle($actor, $unit))
        ->toThrow(UnitHasDependentsException::class, 'Cannot delete "Nightriders" — Cav billets are still mapped to it');

    expect(Unit::find($unit->id))->not->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

// Every other `restrictOnDelete` foreign key to `units`. Each used to reach the database and come
// back as a QueryException, i.e. a 500 on the org-structure screen, instead of a reason.
it('refuses to delete a unit that still has a dependent, naming it', function (Closure $attach, string $reason) {
    $actor = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    $attach($unit);

    expect(fn () => app(DeleteUnit::class)->handle($actor, $unit))
        ->toThrow(UnitHasDependentsException::class, "Cannot delete \"Nightriders\" — {$reason}");

    expect(Unit::find($unit->id))->not->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
})->with([
    'a staff grant' => [
        fn (Unit $unit) => PortalRoleGrant::factory()->create(['unit_id' => $unit->id]),
        'staff grants still give users scope over it',
    ],
    'a recorded practice' => [
        fn (Unit $unit) => app(SectionPracticeRepository::class)->findOrCreate($unit->id, WeekOf::start(CarbonImmutable::now())),
        'attendance has been recorded for it',
    ],
    'a draft weekly report' => [
        fn (Unit $unit) => app(WeeklyReportRepository::class)->saveDraft($unit->id, WeekOf::start(CarbonImmutable::now()), [], null),
        'it has weekly reports',
    ],
    'a sent weekly report' => [
        fn (Unit $unit) => app(WeeklyReportRepository::class)->send($unit->id, WeekOf::start(CarbonImmutable::now()), [], null, User::factory()->create()->id, 'digest', null),
        'it has weekly reports',
    ],
    'a training plan' => [
        fn (Unit $unit) => app(TrainingPlanRepository::class)->save($unit->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, User::factory()->create()->id),
        'it has training plans, and that plan history must not be orphaned.',
    ],
    'a weekly training topic' => [
        fn (Unit $unit) => app(PlatoonTrainingTopicRepository::class)->set($unit->id, WeekOf::start(CarbonImmutable::now()), 'Room clearing', false, User::factory()->create()->id),
        'it has weekly training topics, which are part of its report history.',
    ],
]);

it('is not blocked by a grant, practice, report, plan or topic on a different unit', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $doomed = $repo->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    $other = $repo->create(UnitType::Section, 'Dark Horse', '2/1/E/2-7', null);
    $week = WeekOf::start(CarbonImmutable::now());
    PortalRoleGrant::factory()->create(['unit_id' => $other->id]);
    app(SectionPracticeRepository::class)->findOrCreate($other->id, $week);
    app(WeeklyReportRepository::class)->saveDraft($other->id, $week, [], null);
    app(TrainingPlanRepository::class)->save($other->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, $actor->id);
    app(PlatoonTrainingTopicRepository::class)->set($other->id, $week, 'Room clearing', false, $actor->id);

    app(DeleteUnit::class)->handle($actor, $doomed);

    expect(Unit::find($doomed->id))->toBeNull();
});

// Template versions (3.5) belong to a company and restrict its delete, as does the active pointer.
// Without the check the delete reached the database and came back as a QueryException (a 500).
it('refuses to delete a company that holds template versions, naming them', function () {
    $actor = User::factory()->admin()->create();
    $company = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    app(TemplateVersionRepository::class)->author($company->id, ArtifactType::RollcallBbcode, '[b]{section}[/b]', $actor->id);

    expect(fn () => app(DeleteUnit::class)->handle($actor, $company))
        ->toThrow(UnitHasDependentsException::class, 'Cannot delete "Alpha Company" — it has template versions, and that template history must not be orphaned.');

    expect(Unit::find($company->id))->not->toBeNull()
        ->and(TemplateVersion::count())->toBe(1)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('is not blocked by another company\'s template versions', function () {
    $actor = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $doomed = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $other = $repo->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    app(TemplateVersionRepository::class)->author($other->id, ArtifactType::RollcallBbcode, '[b]{section}[/b]', $actor->id);

    app(DeleteUnit::class)->handle($actor, $doomed);

    expect(Unit::find($doomed->id))->toBeNull();
});

// The command locks the unit row before its checks; a unit another Admin deleted first is not found
// there, so no second `unit.deleted` entry is written for a row that no longer exists.
it('does not audit a delete of a unit that is already gone', function () {
    $actor = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);
    Unit::query()->whereKey($unit->id)->delete();

    expect(fn () => app(DeleteUnit::class)->handle($actor, $unit))->toThrow(ModelNotFoundException::class);

    expect(AuditLogEntry::count())->toBe(0);
});
