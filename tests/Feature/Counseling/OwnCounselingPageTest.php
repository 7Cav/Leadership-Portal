<?php

use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// Decided 2026-10-28 (audit 2, M4): every signed-in trooper may open their own milpac page read-only, with no command
// and no grant, and sees their own DCSs and LORs on it. Nothing else widens: no other trooper, no roster sheet, no
// search result, and never a write on their own records.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    // The rifleman signs in: no command billet and no grant, so their scope is empty.
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->dcs = CounselingRecord::factory()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['section']->id]);
    $this->lor = CounselingRecord::factory()->lor()->create(['trooper_id' => $this->rifleman->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id]);
    Storage::disk('counseling')->put($this->dcs->document_path, counselingPdfBytes('own-dcs'));
});

/** @return array<string, mixed> the milpac page's props */
function ownCounselingPage(object $test, User $user, Trooper $trooper): array
{
    return $test->actingAs($user)->get(route('troopers.show', $trooper->id))->assertOk()->viewData('page')['props'];
}

it('opens a trooper\'s own milpac with their DCSs and LORs, read-only, and no way into their unit', function () {
    $props = ownCounselingPage($this, $this->self, $this->rifleman);

    expect($props['ownPage'])->toBeTrue()
        ->and($props['actions'])->toBe([])
        ->and($props['makeup'])->toBeNull()
        ->and(collect($props['counseling']['records'])->pluck('id')->all())->toEqualCanonicalizing([$this->dcs->id, $this->lor->id])
        ->and(collect($props['counseling']['records'])->pluck('can.continue')->unique()->values()->all())->toBe([false])
        ->and($props['counseling']['sees'])->toBe(['dcs' => true, 'lor' => true])
        ->and($props['counseling']['canFile'])->toBe(['dcs' => false, 'lor' => false]);

    $this->actingAs($this->self)->get(route('counseling.document', $this->dcs->id))->assertOk();
});

it('never lets a trooper start, withdraw or delete their own records', function () {
    $this->actingAs($this->self)->post(route('counseling.store', $this->rifleman->id), ['type' => 'dcs', 'kind' => 'conduct'])->assertNotFound();
    $this->actingAs($this->self)->withSession(counselingPmSession($this->self))->post(route('counseling.withdraw', $this->dcs->id), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->self)->delete(route('counseling.destroy', $this->lor->id))->assertForbidden();

    expect(CounselingRecord::query()->count())->toBe(2)
        ->and($this->dcs->fresh()->reason)->toBe($this->dcs->reason);
});

it('widens nothing else: another trooper, the roster sheet and other search results stay out of reach', function () {
    $other = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['section']->id, 'username' => 'Brandt.Z']);
    $otherDcs = CounselingRecord::factory()->create(['trooper_id' => $other->id, 'issuer_unit_id' => $this->tree['units']['section']->id]);
    Storage::disk('counseling')->put($otherDcs->document_path, counselingPdfBytes('other-dcs'));

    $this->actingAs($this->self)->get(route('troopers.show', $other->id))->assertNotFound();
    $this->actingAs($this->self)->get(route('counseling.document', $otherDcs->id))->assertNotFound();
    $this->actingAs($this->self)->getJson("/roster/troopers/{$this->rifleman->id}")->assertNotFound();
    // The search finds them, as the page opens for them, and nobody else.
    expect(collect($this->actingAs($this->self)->getJson(route('troopers.search', ['q' => 'Brandt']))->assertOk()->json('troopers'))->pluck('id')->all())
        ->toBe([$this->rifleman->id]);
});

it('keeps a leader\'s page as it was: in scope, so not flagged as only their own', function () {
    $sl = $this->tree['users']['sl'];
    $slTrooper = Trooper::query()->where('cav_id', $sl->cav_id)->sole();

    expect(ownCounselingPage($this, $sl, $slTrooper)['ownPage'])->toBeFalse()
        ->and(ownCounselingPage($this, $sl, $this->rifleman)['ownPage'])->toBeFalse();
});

it('does not open the viewer\'s own page through a view-as preview that does not reach it', function () {
    $admin = $this->tree['users']['admin'];
    $mine = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['section']->id]);
    $admin->forceFill(['cav_id' => $mine->cav_id, 'forum_user_id' => $mine->forum_user_id])->save();

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->tree['units']['other']->id])->assertSessionHasNoErrors();

    $this->actingAs($admin)->get(route('troopers.show', $mine->id))->assertNotFound();
});
