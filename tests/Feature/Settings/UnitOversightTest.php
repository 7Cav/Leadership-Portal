<?php

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitOversight;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

// Regimental oversight (decided by the S6, 2026-10-29): an Admin names the staff officer who oversees a battalion or the
// Reservists (an Adjutant General over 2-7, the RDC Commander over ACD, DEVCOM and the Reservists). Portal-owned, audited
// with a reason, and read by ScopeResolver as a staff grant on the unit: read-only, only while the overseer serves.
// Fixture tree: 7CAV ─┬─ 1-7 ─ A/1-7 ─ 1/A/1-7
//                     ├─ 2-7 ─ B/2-7
//                     ├─ ACD
//                     └─ XO ─ RDC ─ RES (groups)

function ovUrl(Unit $unit): string
{
    return "/settings/org-structure/{$unit->id}/oversight";
}

/** @return list<int> */
function ovIds(Collection $ids): array
{
    return $ids->map(fn ($id) => (int) $id)->sort()->values()->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->first = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->first->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->second = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $this->second->id);
    $this->acd = $units->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', $this->regiment->id);
    $this->xo = $units->create(UnitType::Group, 'Executive Officer', 'XO', $this->regiment->id, UnitKind::Staff);
    $this->rdc = $units->create(UnitType::Group, 'Reserve & Development Command', 'RDC', $this->xo->id, UnitKind::Staff);
    $this->reserve = $units->create(UnitType::Group, 'Reservists', 'RES', $this->rdc->id, UnitKind::Reserve);

    // An Adjutant General: Regimental HQ staff are placed on the regiment and read all of it anyway, so the overseer
    // here sits in a staff group, where oversight is the only read they get.
    $this->rob = Trooper::factory()->create(['cav_id' => 701, 'username' => 'Rob.Y', 'status' => TrooperStatus::Combat]);
    $this->rob->forceFill(['primary_section_id' => $this->rdc->id])->save();
    $this->robUser = User::factory()->create(['cav_id' => 701]);
    $this->admin = User::factory()->admin()->create();
});

it('lets an Admin set an overseer, audited with the reason against the unit', function () {
    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => 'Named by the S6.'])
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasNoErrors();

    $row = UnitOversight::query()->sole();
    $entry = AuditLogEntry::query()->where('action', 'unit.oversight_set')->sole();
    expect($row->unit_id)->toBe($this->second->id)
        ->and($row->trooper_id)->toBe($this->rob->id)
        ->and($row->set_by)->toBe($this->admin->id)
        ->and($row->set_by_admin)->toBeNull()
        ->and($entry->reason)->toBe('Named by the S6.')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $this->second->id);
});

it('replaces the overseer, and clears them with a reason on the record', function () {
    $other = Trooper::factory()->create(['username' => 'LaCombe.M', 'status' => TrooperStatus::Combat]);
    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => 'Named by the S6.']);
    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $other->id, 'reason' => 'Swapped.'])->assertSessionHasNoErrors();

    expect(UnitOversight::query()->sole()->trooper_id)->toBe($other->id);

    $this->actingAs($this->admin)->delete(ovUrl($this->second), ['reason' => 'Handed over.'])->assertSessionHasNoErrors();

    expect(UnitOversight::count())->toBe(0)
        ->and(AuditLogEntry::where('action', 'unit.oversight_set')->count())->toBe(2)
        ->and(AuditLogEntry::where('action', 'unit.oversight_cleared')->sole()->reason)->toBe('Handed over.');
});

it('refuses anyone but an Admin, writing nothing', function () {
    $leader = User::factory()->commanding($this->second)->create();

    $this->actingAs($leader)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => 'Me.'])->assertForbidden();
    $this->actingAs($this->robUser)->post(ovUrl($this->first), ['trooper_id' => $this->rob->id, 'reason' => 'Me.'])->assertForbidden();

    UnitOversight::query()->create(['unit_id' => $this->second->id, 'trooper_id' => $this->rob->id]);
    $this->actingAs($leader)->delete(ovUrl($this->second), ['reason' => 'Gone.'])->assertForbidden();

    expect(UnitOversight::count())->toBe(1)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a missing reason, a unit that takes no overseer, a trooper who left, and nothing to clear', function () {
    $left = Trooper::factory()->create(['username' => 'Gone.G', 'status' => TrooperStatus::Inactive]);

    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post(ovUrl($this->alpha), ['trooper_id' => $this->rob->id, 'reason' => 'No.'])->assertSessionHasErrors('oversight');
    $this->actingAs($this->admin)->post(ovUrl($this->rdc), ['trooper_id' => $this->rob->id, 'reason' => 'No.'])->assertSessionHasErrors('oversight');
    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $left->id, 'reason' => 'No.'])->assertSessionHasErrors('oversight');
    $this->actingAs($this->admin)->delete(ovUrl($this->second), ['reason' => 'No.'])->assertSessionHasErrors('oversight');

    expect(UnitOversight::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('refuses an Admin naming themselves, so the read cannot outlast their Admin rights', function () {
    $robAdmin = $this->robUser;
    $robAdmin->forceFill(['is_admin' => true])->save();

    $this->actingAs($robAdmin)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => 'Me.'])
        ->assertSessionHasErrors(['oversight' => 'You cannot make yourself an overseer. Another Admin can.']);

    expect(UnitOversight::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);

    // Another Admin can.
    $this->actingAs($this->admin)->post(ovUrl($this->second), ['trooper_id' => $this->rob->id, 'reason' => 'Named by the S6.'])->assertSessionHasNoErrors();
    expect(UnitOversight::query()->sole()->trooper_id)->toBe($this->rob->id);
});

it('lets the Reservists take an overseer, as battalions do', function () {
    $this->actingAs($this->admin)->post(ovUrl($this->reserve), ['trooper_id' => $this->rob->id, 'reason' => 'RDC Commander.'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(ovUrl($this->acd), ['trooper_id' => $this->rob->id, 'reason' => 'RDC Commander.'])->assertSessionHasNoErrors();

    expect(UnitOversight::query()->pluck('unit_id')->sort()->values()->all())->toBe([$this->acd->id, $this->reserve->id]);
});

it('gives the overseer read scope over the unit and nothing to write', function () {
    $scope = app(ScopeResolver::class);
    $before = ovIds($scope->realScope($this->robUser));

    $this->actingAs($this->admin)->post(ovUrl($this->first), ['trooper_id' => $this->rob->id, 'reason' => 'Named by the S6.']);

    expect(ovIds($scope->realScope($this->robUser)))->toBe(ovIds(collect([...$before, $this->first->id, $this->alpha->id, $this->platoon->id])))
        ->and($scope->resolve($this->robUser)->all())->not->toContain($this->second->id)
        ->and($scope->writeScope($this->robUser)->all())->toBe([])
        ->and($scope->commandsUnit($this->robUser, $this->platoon))->toBeFalse()
        ->and($scope->leads($this->robUser))->toBeFalse()
        ->and($scope->commandedBy($this->robUser)->all())->toBe([]);

    $this->actingAs($this->robUser)->get("/roster/{$this->platoon->id}")->assertOk();
    $this->actingAs($this->robUser)->get("/roster/{$this->bravo->id}")->assertForbidden();
});

it('ends the read when the overseer stops serving, and again when it is cleared', function () {
    $scope = app(ScopeResolver::class);
    $this->actingAs($this->admin)->post(ovUrl($this->first), ['trooper_id' => $this->rob->id, 'reason' => 'Named by the S6.']);

    $this->rob->forceFill(['status' => TrooperStatus::Inactive])->save();
    expect($scope->realScope($this->robUser)->all())->toBe([])
        ->and($scope->realScopes(collect([$this->robUser]))->get($this->robUser->id)->all())->toBe([]);

    $this->rob->forceFill(['status' => TrooperStatus::Combat])->save();
    expect($scope->realScope($this->robUser)->all())->toContain($this->platoon->id);

    $this->actingAs($this->admin)->delete(ovUrl($this->first), ['reason' => 'Handed over.']);
    expect($scope->realScope($this->robUser)->all())->not->toContain($this->platoon->id);
});

it('holds for an overseer who signs in for the first time after it was set', function () {
    $new = Trooper::factory()->create(['cav_id' => 702, 'username' => 'Rogers.S', 'status' => TrooperStatus::Combat]);
    $this->actingAs($this->admin)->post(ovUrl($this->acd), ['trooper_id' => $new->id, 'reason' => 'RDC Commander.']);

    $user = User::factory()->create(['cav_id' => 702]);

    expect(app(ScopeResolver::class)->realScope($user)->all())->toContain($this->acd->id);
});

it('works out an overseer\'s real scope in bulk exactly as one at a time', function () {
    $granted = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $granted->id, 'unit_id' => $this->bravo->id]);
    UnitOversight::query()->create(['unit_id' => $this->first->id, 'trooper_id' => $this->rob->id]);
    UnitOversight::query()->create(['unit_id' => $this->reserve->id, 'trooper_id' => Trooper::where('cav_id', $granted->cav_id)->value('id')]);
    $leader = User::factory()->commanding($this->bravo)->create();
    $left = Trooper::factory()->create(['cav_id' => 703, 'status' => TrooperStatus::Inactive]);
    UnitOversight::query()->create(['unit_id' => $this->second->id, 'trooper_id' => $left->id]);
    User::factory()->create(['cav_id' => 703]);

    $scope = app(ScopeResolver::class);
    $users = User::all();
    $bulk = $scope->realScopes($users);

    foreach ($users as $user) {
        expect(ovIds($bulk->get($user->id)))->toBe(ovIds($scope->realScope($user)), "for {$user->username}");
    }

    expect(ovIds($bulk->get($this->robUser->id)))->toContain($this->platoon->id)
        ->and(ovIds($bulk->get($granted->id)))->toContain($this->reserve->id)->toContain($this->bravo->id)
        ->and(ovIds($bulk->get($leader->id)))->not->toContain($this->first->id);
});

it('leaves view as and act as as they were: a preview of the overseer is their read, and a leader cannot act as them', function () {
    UnitOversight::query()->create(['unit_id' => $this->first->id, 'trooper_id' => $this->rob->id]);
    $scope = app(ScopeResolver::class);

    // An Admin previewing the overseer sees what the overseer sees, and still writes nothing through it.
    app(ViewAs::class)->enter($this->admin, new ViewAsTarget(ViewAsKind::User, $this->robUser->id));
    expect(ovIds($scope->resolve($this->admin)))->toBe(ovIds($scope->realScope($this->robUser)))
        ->and($scope->leads($this->admin))->toBeFalse();
    app(ViewAs::class)->exit();

    // The overseer may preview a unit their oversight reaches, read-only, as a grant holder may.
    expect($scope->evaluate($this->robUser, new ViewAsTarget(ViewAsKind::Unit, $this->alpha->id))?->scope->all())->toContain($this->platoon->id)
        ->and($scope->evaluate($this->robUser, new ViewAsTarget(ViewAsKind::Unit, $this->bravo->id)))->toBeNull();

    // Oversight is not command: the overseer has nobody to act as.
    expect(app(ActAs::class)->candidates($this->robUser))->toBeEmpty();
});
