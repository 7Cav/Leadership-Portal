<?php

use App\Models\AuditLogEntry;
use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Story 2.7: POST /settings/org-structure/troopers/{trooper}/placement.

function placementUrl(Trooper $trooper): string
{
    return "/settings/org-structure/troopers/{$trooper->id}/placement";
}

function placementSectionUnit(): Unit
{
    return Unit::factory()->create(['type' => UnitType::Section]);
}

it('lets an Admin place an unplaced trooper into a section, with a reason', function () {
    $admin = User::factory()->admin()->create();
    $section = placementSectionUnit();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['section_id' => $section->id, 'reason' => 'Cav has not updated the billet yet.'])
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasNoErrors();

    $placed = $trooper->fresh();
    expect($placed->primary_section_id)->toBe($section->id)
        ->and($placed->placement_source)->toBe(PlacementSource::Manual)
        ->and(AuditLogEntry::where('action', 'trooper.placed')->sole()->reason)->toBe('Cav has not updated the billet yet.');
});

it('refuses a non-Admin server-side, even though the page hides the control (AD-6)', function () {
    $section = placementSectionUnit();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs(User::factory()->serving()->create())->post(placementUrl($trooper), ['section_id' => $section->id, 'reason' => 'Because.'])
        ->assertForbidden();

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
});

it('requires authentication', function () {
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->post(placementUrl($trooper), ['section_id' => 1, 'reason' => 'Because.'])->assertRedirect('/login');
});

it('422s a trooper that does not exist, as a form error', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure/troopers/999999/placement', ['section_id' => placementSectionUnit()->id, 'reason' => 'Because.'])
        ->assertSessionHasErrors('placement');
});

it('answers a non-Admin 403 whether or not the trooper exists, so ids cannot be probed', function () {
    $user = User::factory()->serving()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($user)->post(placementUrl($trooper), [])->assertForbidden();
    $this->actingAs($user)->post('/settings/org-structure/troopers/999999/placement', [])->assertForbidden();
});

it('does not route a non-numeric trooper id', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure/troopers/abc/placement', ['section_id' => 1, 'reason' => 'Because.'])
        ->assertNotFound();
});

it('does not route a trooper id too long for an integer, rather than erroring', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure/troopers/99999999999999999999/placement', ['section_id' => placementSectionUnit()->id, 'reason' => 'Because.'])
        ->assertNotFound();

    expect(AuditLogEntry::count())->toBe(0);
});

it('422s a missing or empty reason, and places nothing', function (array $payload) {
    $admin = User::factory()->admin()->create();
    $section = placementSectionUnit();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['section_id' => $section->id, ...$payload])
        ->assertSessionHasErrors('reason');

    expect($trooper->fresh()->primary_section_id)->toBeNull()
        ->and(AuditLogEntry::count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['reason' => '']],
    'spaces' => [['reason' => '   ']],
]);

it('422s a reason over 500 characters', function () {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['section_id' => placementSectionUnit()->id, 'reason' => str_repeat('x', 501)])
        ->assertSessionHasErrors('reason');
});

it('422s a section id that is missing or does not exist', function (array $payload) {
    $admin = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['reason' => 'Because.', ...$payload])
        ->assertSessionHasErrors('section_id');
})->with([
    'missing' => [[]],
    'unknown' => [['section_id' => 999999]],
    'not a number' => [['section_id' => 'abc']],
]);

it('places a trooper on an HQ node too, since staff sit on their HQ (2026-10-24)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['section_id' => $regiment->id, 'reason' => 'Regimental staff.'])
        ->assertSessionHasNoErrors();

    expect($trooper->fresh()->primary_section_id)->toBe($regiment->id);
});

it('422s a trooper who is already placed, leaving the existing placement', function () {
    $admin = User::factory()->admin()->create();
    $current = placementSectionUnit();
    $trooper = Trooper::factory()->create(['primary_section_id' => $current->id, 'placement_source' => PlacementSource::Sync]);

    $this->actingAs($admin)->post(placementUrl($trooper), ['section_id' => placementSectionUnit()->id, 'reason' => 'Because.'])
        ->assertSessionHasErrors('placement');

    expect($trooper->fresh()->primary_section_id)->toBe($current->id);
});

it('refuses a placement while the Admin is previewing a leader (read-only preview, 1.13)', function () {
    $admin = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $section = placementSectionUnit();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $unit->id]);

    $this->post(placementUrl($trooper), ['section_id' => $section->id, 'reason' => 'Because.'])
        ->assertForbidden();

    expect($trooper->fresh()->primary_section_id)->toBeNull();
});
