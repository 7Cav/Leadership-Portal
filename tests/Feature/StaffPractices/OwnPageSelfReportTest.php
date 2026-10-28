<?php

use App\Models\CrossSectionSighting;
use App\Models\Trooper;
use App\Models\User;

require_once __DIR__.'/fixture.php';

// Decided 2026-10-28 (audit 2, M4): a staff member opens their own milpac page with no command and no grant, so the
// department members who lead nothing can self-report the practices they attended there. It opens nobody else's.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    // A member of S6 with no billet that commands anything and no grant: their scope is empty.
    $this->member = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['s6']->id, 'username' => 'Ibarra.N']);
    $this->user = User::factory()->create(['cav_id' => $this->member->cav_id, 'username' => 'Ibarra.N']);
    $this->body = ['section_id' => $this->tree['units']['host']->id, 'week' => '2026-10-03'];
});

it('lets a staff member with no command and no grant self-report from their own page, and take it back', function () {
    $panel = $this->actingAs($this->user)->get(route('troopers.show', $this->member->id))->assertOk()->viewData('page')['props']['staffPractices'];

    expect($panel['canRecord'])->toBeTrue()
        ->and($panel['weeks'][0])->toBe('2026-10-03')
        ->and(collect($panel['sections'])->pluck('id'))->toContain($this->tree['units']['host']->id);

    $this->actingAs($this->user)->post(route('staff-practices.store', $this->member->id), $this->body)
        ->assertRedirect(route('troopers.show', $this->member->id));

    $entry = CrossSectionSighting::query()->sole();
    expect($entry->trooper_id)->toBe($this->member->id)
        ->and($entry->recorded_by)->toBe($this->user->id)
        ->and($entry->isStaff())->toBeTrue();

    $this->actingAs($this->user)->post(route('staff-practices.remove', $this->member->id), $this->body)
        ->assertRedirect(route('troopers.show', $this->member->id));
    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('opens no other staff member\'s page and enters nothing for them', function () {
    $colleague = $this->tree['troopers']['s6Member'];

    $this->actingAs($this->user)->get(route('troopers.show', $colleague->id))->assertNotFound();
    $this->actingAs($this->user)->post(route('staff-practices.store', $colleague->id), $this->body)->assertNotFound();

    expect(CrossSectionSighting::query()->count())->toBe(0);
});
