<?php

use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\UnitActingLead;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #381 review: the echelon above counsels, never one below. A unit the trooper commands, by billet or acting lead, and
// everything under it, gives no say over their counseling; and acting as a billet or a person never reaches the one
// really doing the acting.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->file = fn (array $overrides = []) => [
        'type' => 'dcs',
        'counseled_on' => now()->subDays(3)->toDateString(),
        'reason' => 'Missed two section practices without notice.',
        'document' => counselingPdf(),
        ...$overrides,
    ];
});

function ownCommandDcs(Trooper $trooper, int $issuerUnitId): CounselingRecord
{
    $record = CounselingRecord::factory()->create(['trooper_id' => $trooper->id, 'issuer_unit_id' => $issuerUnitId, 'reason' => 'Private counseling text.']);
    Storage::disk('counseling')->put($record->document_path, counselingPdfBytes('own-command'));

    return $record;
}

function ownCommandPage(object $test, User $user, Trooper $trooper): array
{
    return $test->actingAs($user)->get(route('troopers.show', $trooper->id))->assertOk()->viewData('page')['props']['counseling'];
}

describe('a trooper who commands a unit above their own placement', function () {
    beforeEach(function () {
        // A rifleman of 1st Section made acting Platoon Leader of their own platoon: their Section Leader is now below them.
        UnitActingLead::query()->forceCreate(['unit_id' => $this->tree['units']['platoon']->id, 'trooper_id' => $this->rifleman->id]);
        $this->dcs = ownCommandDcs($this->rifleman, $this->tree['units']['section']->id);
    });

    it('keeps their Section Leader from filing on them or seeing their DCSs', function () {
        $sl = $this->tree['users']['sl'];

        $this->actingAs($sl)->post(route('counseling.store', $this->rifleman->id), ($this->file)())->assertForbidden();
        expect(CounselingRecord::query()->count())->toBe(1);

        $props = ownCommandPage($this, $sl, $this->rifleman);
        expect(collect($props['records'])->pluck('id')->all())->not->toContain($this->dcs->id)
            ->and($props['canFile'])->toBe(['dcs' => false, 'lor' => false]);

        $this->actingAs($sl)->get(route('counseling.document', $this->dcs->id))->assertNotFound();
    });

    it('keeps their Section Leader from changing or deleting a DCS filed before', function () {
        $sl = $this->tree['users']['sl'];

        $this->actingAs($sl)->patch(route('counseling.update', $this->dcs->id), [
            'counseled_on' => now()->subDays(4)->toDateString(), 'reason' => 'Changed.', 'audit_reason' => 'x',
        ])->assertNotFound();
        $this->actingAs($sl)->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'x'])->assertNotFound();

        expect($this->dcs->fresh()->reason)->toBe('Private counseling text.');
    });

    it('leaves it to the echelon above their command', function () {
        $co = $this->tree['users']['co'];

        $this->actingAs($co)->post(route('counseling.store', $this->rifleman->id), ($this->file)(['type' => 'lor']))->assertRedirect();
        expect(CounselingRecord::query()->count())->toBe(2)
            ->and(collect(ownCommandPage($this, $co, $this->rifleman)['records'])->pluck('id')->all())->toContain($this->dcs->id);
        $this->actingAs($co)->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'Filed by mistake.'])->assertRedirect();
    });
});

it('treats a billet the same: a Platoon Leader placed in a section is beyond that section\'s leader', function () {
    $pl = Trooper::query()->where('cav_id', $this->tree['users']['pl']->cav_id)->sole();
    $pl->forceFill(['primary_section_id' => $this->tree['units']['section']->id])->save();
    $dcs = ownCommandDcs($pl, $this->tree['units']['company']->id);

    $this->actingAs($this->tree['users']['sl'])->post(route('counseling.store', $pl->id), ($this->file)())->assertForbidden();
    $this->actingAs($this->tree['users']['sl'])->get(route('counseling.document', $dcs->id))->assertNotFound();
    $this->actingAs($this->tree['users']['co'])->get(route('counseling.document', $dcs->id))->assertOk();
});

describe('acting as someone', function () {
    beforeEach(function () {
        // The Admin is a trooper of 1st Section in their own right.
        $this->adminTrooper = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['section']->id, 'username' => 'Hale.S']);
        $this->admin = $this->tree['users']['admin'];
        $this->admin->forceFill(['cav_id' => $this->adminTrooper->cav_id])->save();
        $this->dcs = ownCommandDcs($this->adminTrooper, $this->tree['units']['company']->id);
    });

    it('never lets an Admin acting as a billet file on themselves', function (string $unit) {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->tree['units'][$unit]->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post(route('counseling.store', $this->adminTrooper->id), ($this->file)(['type' => 'dcs']))->assertForbidden();
        $this->actingAs($this->admin)->post(route('counseling.store', $this->adminTrooper->id), ($this->file)(['type' => 'lor']))->assertForbidden();
        $this->actingAs($this->admin)->get(route('counseling.document', $this->dcs->id))->assertNotFound();

        // The billet still counsels everyone else in reach.
        $this->actingAs($this->admin)->post(route('counseling.store', $this->rifleman->id), ($this->file)())->assertRedirect();
        expect(CounselingRecord::query()->where('trooper_id', $this->adminTrooper->id)->count())->toBe(1);
    })->with(['section', 'platoon']);

    it('never lets an Admin acting as a person file on, read the DCSs of, or change the records of themselves', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->tree['users']['co']->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post(route('counseling.store', $this->adminTrooper->id), ($this->file)(['type' => 'lor']))->assertForbidden();

        $props = ownCommandPage($this, $this->admin, $this->adminTrooper);
        expect(collect($props['records'])->pluck('id')->all())->not->toContain($this->dcs->id)
            ->and($props['canFile'])->toBe(['dcs' => false, 'lor' => false]);

        $this->actingAs($this->admin)->get(route('counseling.document', $this->dcs->id))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('counseling.destroy', $this->dcs->id), ['audit_reason' => 'x'])->assertNotFound();
        expect($this->dcs->fresh())->not->toBeNull();

        // The person acted as keeps their reach over everyone else.
        $this->actingAs($this->admin)->get(route('counseling.document', ownCommandDcs($this->rifleman, $this->tree['units']['company']->id)->id))->assertOk();
    });
});
