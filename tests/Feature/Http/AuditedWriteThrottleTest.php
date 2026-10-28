<?php

use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\Route;

// The audited rollcall and report writes that had no cap of their own share one per-user throttle, so a leader looping
// them cannot flood their section's audit log. The rollcall's mark autosave keeps its own terms.

it('puts every audited write without a cap of its own behind the shared throttle, and leaves the marks alone', function () {
    $names = ['rollcall.fireteams.role', 'rollcall.fireteams.options', 'rollcall.fireteams.policy', 'rollcall.unmatched.dismiss', 'rollcall.unmatched.restore', 'reports.topic', 'reports.topic.clear', 'rollcall.guests.store', 'rollcall.guests.remove', 'view-as.store', 'view-as.destroy', 'training-plans.save', 'training-plans.approve', 'training-plans.return', 'counseling.update', 'counseling.destroy', 'rollcall.template.pick', 'settings.templates.store', 'settings.templates.create', 'settings.templates.activate', 'settings.templates.switch', 'settings.templates.company-active'];

    foreach ($names as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:audited-writes');
    }

    expect(Route::getRoutes()->getByName('rollcall.store')->gatherMiddleware())->not->toContain('throttle:audited-writes');
});

it('answers 429 after 60 of these writes in a minute from one user, across all of them, without slowing anyone else', function () {
    $units = app(UnitRepository::class);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', null);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $week = '2026-10-03';
    $requests = [
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams", []),
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams/options", []),
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams/policy", []),
        fn () => $this->postJson("/rollcall/{$section->id}/unmatched/dismiss", []),
        fn () => $this->postJson("/rollcall/{$section->id}/unmatched/restore", []),
        fn () => $this->putJson("/reports/{$platoon->id}/{$week}/topic", []),
        fn () => $this->deleteJson("/reports/{$platoon->id}/{$week}/topic"),
    ];
    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();

    $this->actingAs($user);
    foreach (range(0, 59) as $i) {
        expect($requests[$i % count($requests)]()->status())->not->toBe(429);
    }

    foreach ($requests as $request) {
        $request()->assertTooManyRequests();
    }

    $this->actingAs($other);
    expect($requests[0]()->status())->not->toBe(429);
});

it('gives filing a DCS or LOR its own, tighter bucket: 10 uploads a minute per user', function () {
    $route = Route::getRoutes()->getByName('counseling.store');
    expect($route->gatherMiddleware())->toContain('throttle:counseling-uploads')
        ->not->toContain('throttle:audited-writes');

    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();

    // No such trooper: each request is refused with a 404, and still spends a slot.
    $this->actingAs($user);
    foreach (range(1, 10) as $i) {
        expect($this->post('/troopers/999999/counseling', [])->status())->toBe(404);
    }
    $this->post('/troopers/999999/counseling', [])->assertTooManyRequests();

    // A separate bucket: the shared audited writes are untouched by it.
    expect($this->deleteJson('/reports/999999/2026-10-03/topic')->status())->not->toBe(429);

    $this->actingAs($other);
    expect($this->post('/troopers/999999/counseling', [])->status())->toBe(404);
});
