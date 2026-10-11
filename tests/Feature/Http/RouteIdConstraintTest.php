<?php

use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

/**
 * Path ids are capped at 18 digits (routes/web.php). An id longer than PHP's int can hold used to
 * reach the `int` parameter of ReportRollupController and die in a TypeError (a 500); it must be
 * a plain 404, on every route that takes an id.
 */
it('answers a 20-digit id with 404, not a 500', function (string $method, string $path) {
    $this->actingAs(User::factory()->admin()->create())
        ->call($method, $path)
        ->assertNotFound();
})->with([
    'report roll-up' => ['GET', '/reports/99999999999999999999'],
    'rename a unit' => ['PATCH', '/settings/org-structure/99999999999999999999'],
    'delete a unit' => ['DELETE', '/settings/org-structure/99999999999999999999'],
    'revoke a grant' => ['DELETE', '/settings/staff-grants/99999999999999999999'],
]);

it('still routes an 18-digit id, which an int can hold', function () {
    $unit = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $admin = User::factory()->admin()->create();

    // The unit's own id, zero-padded to the full 18 digits: it reaches the controller and
    // resolves, so the cap rejects nothing an int can hold.
    $this->actingAs($admin)
        ->get('/reports/'.str_pad((string) $unit->id, 18, '0', STR_PAD_LEFT))
        ->assertRedirect();

    // And an 18-digit id nobody holds is the controller's own 404, not a TypeError.
    $this->actingAs($admin)->get('/reports/999999999999999999')->assertNotFound();
});
