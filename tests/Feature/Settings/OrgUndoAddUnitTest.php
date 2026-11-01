<?php

use App\Commands\Units\DeleteUnit;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;

// The leader who added a unit may undo the add (delete it) for 60 minutes, while it is still empty and while they
// could still add it there. Admins keep their own delete.

const UNDO_REFUSED = 'You can undo this for 60 minutes after adding it, while it is still empty.';

/** A serving trooper who leads `$unit` (acting lead), and their account. */
function uaLeaderOf(Unit $unit): User
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    DB::table('unit_acting_leads')->insert(['unit_id' => $unit->id, 'trooper_id' => $trooper->id, 'created_at' => now(), 'updated_at' => now()]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

/** Adds a second section under the platoon as `$as`, through the page, and returns it. */
function uaAdd(object $test, User $as): Unit
{
    $test->actingAs($as)->post('/settings/org-structure', ['type' => 'section', 'parent_id' => $test->platoon->id, 'ordinal' => '2'])
        ->assertSessionHasNoErrors();

    return Unit::where('callsign', '2/1/A/3-7')->firstOrFail();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '3rd Battalion', '3-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/3-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/3-7', $this->company->id);
    $this->co = uaLeaderOf($this->company);
});

it('keeps who added a unit', function () {
    expect(uaAdd($this, $this->co)->created_by)->toBe($this->co->id);
});

it('lets the leader who added a unit undo it within the hour, audited as a delete with the undo reason', function () {
    $section = uaAdd($this, $this->co);
    $this->travel(59)->minutes();

    $this->actingAs($this->co)->delete("/settings/org-structure/{$section->id}")
        ->assertRedirect('/settings/org-structure')->assertSessionHasNoErrors();

    $entry = AuditLogEntry::where('action', 'unit.deleted')->sole();
    expect(Unit::find($section->id))->toBeNull()
        ->and($entry->actor_id)->toBe($this->co->id)
        ->and($entry->target_id)->toBe((string) $section->id)
        ->and($entry->reason)->toBe(DeleteUnit::UNDO_REASON);
});

it('refuses the undo after the hour', function () {
    $section = uaAdd($this, $this->co);
    $this->travel(61)->minutes();

    $this->actingAs($this->co)->delete("/settings/org-structure/{$section->id}")->assertForbidden();

    expect(Unit::find($section->id))->not->toBeNull()
        ->and(AuditLogEntry::where('action', 'unit.deleted')->exists())->toBeFalse();
});

it('refuses another leader, even one who may add there', function () {
    $section = uaAdd($this, $this->co);

    $this->actingAs(uaLeaderOf($this->battalion))->delete("/settings/org-structure/{$section->id}")->assertForbidden();

    expect(Unit::find($section->id))->not->toBeNull();
});

it('refuses the undo once the leader may no longer add there', function () {
    $section = uaAdd($this, $this->co);
    DB::table('unit_acting_leads')->where('unit_id', $this->company->id)->delete();

    $this->actingAs($this->co)->delete("/settings/org-structure/{$section->id}")->assertForbidden();

    expect(Unit::find($section->id))->not->toBeNull();
});

it('refuses the undo of a unit that is no longer empty, with the reason', function (Closure $attach) {
    $section = uaAdd($this, $this->co);
    $attach($this, $section);

    $this->actingAs($this->co)->delete("/settings/org-structure/{$section->id}")
        ->assertSessionHasErrors(['delete' => UNDO_REFUSED]);

    expect(Unit::find($section->id))->not->toBeNull()
        ->and(AuditLogEntry::where('action', 'unit.deleted')->exists())->toBeFalse();
})->with([
    'a trooper placed in it' => [fn ($test, Unit $section) => Trooper::factory()->create(['primary_section_id' => $section->id])],
    'a nickname' => [fn ($test, Unit $section) => $section->forceFill(['label' => 'Jaegers'])->save()],
    'a practice slot' => [fn ($test, Unit $section) => DB::table('section_practice_slots')->insert([
        'section_id' => $section->id, 'weekday' => 2, 'start_minute' => 1200, 'created_at' => now(), 'updated_at' => now(),
    ])],
    'an audited change since' => [fn ($test, Unit $section) => DB::table('audit_log')->insert([
        'actor_id' => $test->co->id, 'action' => 'unit.label_set', 'target_type' => 'Unit', 'target_id' => (string) $section->id,
        'reason' => null, 'content_digest' => str_repeat('0', 64), 'created_at' => now(),
    ])],
]);

it('refuses the undo of a unit with a unit under it', function () {
    $units = app(UnitRepository::class);
    $platoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/3-7', $this->company->id, createdBy: $this->co->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/2/A/3-7', $platoon->id);

    $bc = uaLeaderOf($this->battalion);
    $this->actingAs($bc)->delete("/settings/org-structure/{$platoon->id}")->assertForbidden();
    $platoon->forceFill(['created_by' => $bc->id])->save();
    $this->actingAs($bc)->delete("/settings/org-structure/{$platoon->id}")->assertSessionHasErrors(['delete' => UNDO_REFUSED]);

    expect(Unit::find($platoon->id))->not->toBeNull()
        ->and(Unit::find($section->id))->not->toBeNull();
});

it('refuses a unit the import made, which nobody added by hand', function () {
    $this->actingAs($this->co)->delete("/settings/org-structure/{$this->platoon->id}")->assertForbidden();
});

it('leaves an Admin delete as it was: any unit, any time, without the undo reason', function () {
    $section = uaAdd($this, $this->co);
    $this->travel(3)->hours();

    $this->actingAs(User::factory()->admin()->create())->delete("/settings/org-structure/{$section->id}")->assertSessionHasNoErrors();

    expect(Unit::find($section->id))->toBeNull()
        ->and(AuditLogEntry::where('action', 'unit.deleted')->sole()->reason)->toBeNull();
});

it('tells the page until when the leader who added the unit may undo it, and nobody else', function () {
    $section = uaAdd($this, $this->co);
    $until = fn (User $as) => collect($this->actingAs($as)->get('/settings/org-structure')->viewData('page')['props']['units'])
        ->firstWhere('id', $section->id)['undo_add_until'];

    expect($until($this->co))->toBe($section->created_at->addMinutes(60)->toIso8601String())
        ->and($until(uaLeaderOf($this->battalion)))->toBeNull()
        ->and($until(User::factory()->admin()->create()))->toBeNull();

    $this->travel(61)->minutes();
    expect($until($this->co))->toBeNull();
});

it('throttles the undo with the shared audited-writes bucket', function () {
    $section = uaAdd($this, $this->co);
    // The add above took one request from the bucket of 60.
    foreach (range(1, 59) as $_) {
        $this->actingAs($this->co)->delete('/settings/org-structure/999999')->assertForbidden();
    }

    $this->actingAs($this->co)->delete("/settings/org-structure/{$section->id}")->assertTooManyRequests();

    expect(Unit::find($section->id))->not->toBeNull();
});
