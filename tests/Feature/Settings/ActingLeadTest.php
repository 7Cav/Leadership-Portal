<?php

use App\Authorization\ScopeResolver;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Acting leads: an Admin assigns a serving trooper to lead a unit the roster
// names no lead for, such as a DEVCOM Lead running DEVCOM starter platoons. It is a Platoon Leader's command over the
// unit while they serve, audited with a required reason, one per unit, several per trooper.

function alUrl(Unit $unit): string
{
    return "/settings/org-structure/{$unit->id}/acting-lead";
}

/** @return array<int, array<string, mixed>> */
function alUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->devcom = $units->create(UnitType::Battalion, 'DEVCOM', 'DEVCOM', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Delta Company', 'D/DEVCOM', $this->devcom->id);
    $this->p1 = $units->create(UnitType::Platoon, '1st Platoon', '1/D/DEVCOM', $this->company->id);
    $this->p2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/D/DEVCOM', $this->company->id);
    $this->p3 = $units->create(UnitType::Platoon, '3rd Platoon', '3/D/DEVCOM', $this->company->id);
    $this->s1 = $units->create(UnitType::Section, '1st Section', '1/1/D/DEVCOM', $this->p1->id);
    $this->s2 = $units->create(UnitType::Section, '1st Section', '1/2/D/DEVCOM', $this->p2->id);
    $this->staff = $units->create(UnitType::Group, 'DEVCOM Staff', 'DEVCOM STAFF', $this->regiment->id, UnitKind::Staff);

    $this->taylor = Trooper::factory()->create(['cav_id' => 501, 'username' => 'Taylor.C', 'billet' => 'DEVCOM Lead', 'status' => TrooperStatus::Combat]);
    $this->taylor->forceFill(['primary_section_id' => $this->staff->id])->save();
    $this->user = User::factory()->create(['cav_id' => 501]);
    $this->admin = User::factory()->admin()->create();
});

it('lets an Admin make a serving trooper acting lead of several units, audited with a reason', function () {
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Runs the 1st starter platoon.'])
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(alUrl($this->p3), ['trooper_id' => $this->taylor->id, 'reason' => 'Runs the 3rd too.'])
        ->assertSessionHasNoErrors();

    expect(UnitActingLead::query()->pluck('unit_id')->sort()->values()->all())->toBe([$this->p1->id, $this->p3->id])
        ->and(AuditLogEntry::where('action', 'unit.acting_lead_assigned')->pluck('reason')->all())->toBe(['Runs the 1st starter platoon.', 'Runs the 3rd too.']);
});

it('gives an acting lead exactly a Platoon Leader\'s command over the unit', function () {
    $scope = app(ScopeResolver::class);
    expect($scope->writeScope($this->user)->all())->toBe([]);

    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Starter platoon lead.']);

    expect($scope->writeScope($this->user)->sort()->values()->all())->toBe([$this->p1->id, $this->s1->id])
        ->and($scope->resolve($this->user)->all())->not->toContain($this->p2->id)
        ->and($scope->commandsUnit($this->user, $this->s1))->toBeTrue()
        ->and($scope->commandsUnit($this->user, $this->p2))->toBeFalse();
});

it('ends the command when the trooper stops serving, and again on removal', function () {
    $scope = app(ScopeResolver::class);
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Starter platoon lead.']);

    $this->taylor->forceFill(['status' => TrooperStatus::Inactive])->save();
    expect($scope->writeScope($this->user)->all())->toBe([]);
    $units = alUnits($this->actingAs($this->admin)->get('/settings/org-structure'));
    expect($units[$this->p1->id]['lead'])->toBeNull()
        ->and($units[$this->p1->id]['lead_vacant'])->toBeTrue();

    $this->taylor->forceFill(['status' => TrooperStatus::Combat])->save();
    expect($scope->writeScope($this->user)->all())->toContain($this->p1->id);

    $this->actingAs($this->admin)->delete(alUrl($this->p1), ['reason' => 'Handed over.'])->assertSessionHasNoErrors();
    expect($scope->writeScope($this->user)->all())->toBe([])
        ->and(UnitActingLead::count())->toBe(0)
        ->and(AuditLogEntry::where('action', 'unit.acting_lead_removed')->sole()->reason)->toBe('Handed over.');
});

it('audits assign and remove against the unit, so the audit log\'s unit filter still finds both after the removal', function () {
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Starter platoon lead.']);
    $this->actingAs($this->admin)->delete(alUrl($this->p1), ['reason' => 'Handed over.']);

    $entries = AuditLogEntry::query()->whereIn('action', ['unit.acting_lead_assigned', 'unit.acting_lead_removed'])->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('target_type')->unique()->values()->all())->toBe(['Unit'])
        ->and($entries->pluck('target_id')->unique()->values()->all())->toBe([(string) $this->p1->id]);

    $actions = null;
    $this->actingAs($this->admin)->get('/settings/audit-log?unit_id='.$this->p1->id)->assertOk()->assertInertia(function ($page) use (&$actions) {
        $actions = collect($page->toArray()['props']['entries']['data'])->pluck('action')->sort()->values()->all();
    });
    expect($actions)->toContain('unit.acting_lead_assigned')->toContain('unit.acting_lead_removed');
});

it('shows the acting lead as the unit\'s lead, and an unled platoon as vacant', function () {
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Starter platoon lead.']);
    $units = alUnits($this->actingAs($this->admin)->get('/settings/org-structure'));

    expect($units[$this->p1->id]['lead']['source'])->toBe('acting')
        ->and($units[$this->p1->id]['lead']['displayName'])->toContain('Taylor.C')
        ->and($units[$this->p1->id]['acting_lead']['id'])->toBe($this->taylor->id)
        ->and($units[$this->p1->id]['lead_vacant'])->toBeFalse()
        ->and($units[$this->p2->id]['lead'])->toBeNull()
        ->and($units[$this->p2->id]['lead_vacant'])->toBeTrue()
        // A section no billet commands has no lead to be vacant.
        ->and($units[$this->s2->id]['lead_vacant'])->toBeFalse();

    $this->actingAs($this->admin)->get("/roster/{$this->p1->id}")
        ->assertInertia(fn ($page) => $page->where('units.0.leader.source', 'acting')->where('units.0.leader.name', 'Taylor.C'));
});

it('refuses a second acting lead, a departed trooper, a group or the regiment, a blank reason and a non-Admin', function () {
    $other = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    $gone = Trooper::factory()->create(['status' => TrooperStatus::Inactive]);
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Lead.']);

    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $other->id, 'reason' => 'Lead.'])->assertSessionHasErrors('acting_lead');
    $this->actingAs($this->admin)->post(alUrl($this->p2), ['trooper_id' => $gone->id, 'reason' => 'Lead.'])->assertSessionHasErrors('acting_lead');
    $this->actingAs($this->admin)->post(alUrl($this->staff), ['trooper_id' => $other->id, 'reason' => 'Lead.'])->assertSessionHasErrors('acting_lead');
    $this->actingAs($this->admin)->post(alUrl($this->regiment), ['trooper_id' => $other->id, 'reason' => 'Lead.'])->assertSessionHasErrors('acting_lead');
    $this->actingAs($this->admin)->post(alUrl($this->p2), ['trooper_id' => $other->id, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->delete(alUrl($this->p2), ['reason' => 'Nothing there.'])->assertSessionHasErrors('acting_lead');
    $this->actingAs($this->user)->post(alUrl($this->p2), ['trooper_id' => $other->id, 'reason' => 'Lead.'])->assertForbidden();
    $this->actingAs($this->user)->delete(alUrl($this->p1), ['reason' => 'Lead.'])->assertForbidden();

    expect(UnitActingLead::count())->toBe(1)
        ->and(AuditLogEntry::where('action', 'unit.acting_lead_assigned')->count())->toBe(1);
});

// Audit 3 (2026-10-29): the command would outlast the Admin who gave it to themselves, which an Admin by billet loses
// with a rename, and a unit whose commanding billet a serving trooper holds already has its lead.
it('refuses an Admin making themselves acting lead, by flag or by an approved billet', function () {
    $this->admin->forceFill(['cav_id' => Trooper::factory()->create(['status' => TrooperStatus::Combat])->cav_id])->save();
    $mine = Trooper::query()->where('cav_id', $this->admin->cav_id)->sole();

    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $mine->id, 'reason' => 'Covering it myself.'])
        ->assertSessionHasErrors(['acting_lead' => 'You cannot make yourself an acting lead. Another Admin can.']);

    $chief = Trooper::factory()->create(['status' => TrooperStatus::Combat, 'billet' => 'Regimental Chief of Staff', 'cav_position_id' => 9010]);
    $chief->forceFill(['primary_section_id' => $this->regiment->id])->save();
    AdminBillet::query()->create([
        'cav_position_id' => 9010, 'title' => 'Regimental Chief of Staff', 'group_title' => 'Regimental HQ',
        'proposed_title' => 'Regimental Chief of Staff', 'proposed_group_title' => 'Regimental HQ', 'status' => AdminBilletStatus::Approved,
    ]);
    $chiefUser = User::factory()->create(['cav_id' => $chief->cav_id]);
    expect(app(ScopeResolver::class)->isAdmin($chiefUser))->toBeTrue();

    $this->actingAs($chiefUser)->post(alUrl($this->devcom), ['trooper_id' => $chief->id, 'reason' => 'Covering it myself.'])
        ->assertSessionHasErrors(['acting_lead' => 'You cannot make yourself an acting lead. Another Admin can.']);
    // Another Admin may.
    $this->actingAs($chiefUser)->post(alUrl($this->p2), ['trooper_id' => $this->taylor->id, 'reason' => 'Starter platoon lead.'])
        ->assertSessionHasNoErrors();

    expect(UnitActingLead::query()->pluck('trooper_id')->all())->toBe([$this->taylor->id]);
});

it('refuses a unit whose commanding billet a serving trooper holds, and takes one whose holder left or holds a junior billet', function () {
    $pl = User::factory()->commanding($this->p1, ['primary_section_id' => $this->p1->id, 'username' => 'Mercer.J', 'billet' => 'Platoon Leader 1/D/DEVCOM'])->create();
    $plTrooper = Trooper::query()->where('cav_id', $pl->cav_id)->sole();

    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Lead.'])
        ->assertSessionHasErrors(['acting_lead' => "1/D/DEVCOM is led by {$plTrooper->display_name} on their billet, so it takes no acting lead."]);
    expect(UnitActingLead::count())->toBe(0);

    // Their Platoon Sergeant does not lead the platoon (#444), so a platoon with only a PSG takes one.
    User::factory()->commanding($this->p2, ['primary_section_id' => $this->p2->id, 'billet' => 'Platoon Sergeant 2/D/DEVCOM'])->create();
    $this->actingAs($this->admin)->post(alUrl($this->p2), ['trooper_id' => $this->taylor->id, 'reason' => 'Lead.'])->assertSessionHasNoErrors();

    // The Platoon Leader leaves the Cav: the platoon is vacant again and takes one.
    $plTrooper->forceFill(['status' => TrooperStatus::Inactive])->save();
    $this->actingAs($this->admin)->post(alUrl($this->p1), ['trooper_id' => $this->taylor->id, 'reason' => 'Lead.'])->assertSessionHasNoErrors();

    expect(UnitActingLead::query()->pluck('unit_id')->sort()->values()->all())->toBe([$this->p1->id, $this->p2->id]);
});
