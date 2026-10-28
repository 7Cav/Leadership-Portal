<?php

use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\Route;

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
    'trooper sheet' => ['GET', '/roster/troopers/99999999999999999999'],
    'revoke a grant' => ['DELETE', '/settings/staff-grants/99999999999999999999'],
    'approve an Admin billet' => ['POST', '/settings/staff-grants/admin-billets/99999999999999999999/approve'],
    'reject an Admin billet' => ['POST', '/settings/staff-grants/admin-billets/99999999999999999999/reject'],
    'edit a game' => ['PATCH', '/settings/games/99999999999999999999'],
    'retire a game' => ['POST', '/settings/games/99999999999999999999/retire'],
    'sync a unit roster' => ['POST', '/units/99999999999999999999/roster-sync'],
    // Routes that once added their own `whereNumber()`, which replaced the global cap with `[0-9]+`.
    'weekly report' => ['GET', '/reports/99999999999999999999/2026-10-03'],
    'save a report draft' => ['PUT', '/reports/99999999999999999999/2026-10-03'],
    'send a report' => ['POST', '/reports/99999999999999999999/2026-10-03/send'],
    'training plan' => ['GET', '/reports/99999999999999999999/plans/2026-11'],
    'save a training plan' => ['PUT', '/reports/99999999999999999999/plans/2026-11'],
    'approve a training plan' => ['POST', '/reports/99999999999999999999/plans/2026-11/approve'],
    'send back a training plan' => ['POST', '/reports/99999999999999999999/plans/2026-11/return'],
    'rollcall' => ['GET', '/rollcall/99999999999999999999'],
    'mark attendance' => ['POST', '/rollcall/99999999999999999999'],
    'link an unmatched player' => ['POST', '/rollcall/99999999999999999999/unmatched/link'],
    'dismiss an unmatched player' => ['POST', '/rollcall/99999999999999999999/unmatched/dismiss'],
    'undo a dismissal' => ['POST', '/rollcall/99999999999999999999/unmatched/restore'],
    'retry capture' => ['POST', '/rollcall/99999999999999999999/capture'],
    'search for a guest' => ['GET', '/rollcall/99999999999999999999/guests/search'],
    'add a guest' => ['POST', '/rollcall/99999999999999999999/guests'],
    'remove a guest' => ['POST', '/rollcall/99999999999999999999/guests/remove'],
    'confirm a practice' => ['POST', '/rollcall/99999999999999999999/confirm'],
    'mark rollcall posted' => ['POST', '/rollcall/99999999999999999999/posted'],
    'set a game identity' => ['PUT', '/game-identities/99999999999999999999/hll'],
    'remove a game identity' => ['DELETE', '/game-identities/99999999999999999999/hll'],
    'place a trooper' => ['POST', '/settings/org-structure/troopers/99999999999999999999/placement'],
    'activate a template version' => ['POST', '/settings/templates/99999999999999999999/activate'],
    'file a counseling record' => ['POST', '/troopers/99999999999999999999/counseling'],
    'correct a counseling record' => ['PATCH', '/counseling/99999999999999999999'],
    'delete a counseling record' => ['DELETE', '/counseling/99999999999999999999'],
    'download a counseling document' => ['GET', '/counseling/99999999999999999999/document'],
    'switch a template' => ['POST', '/settings/templates/99999999999999999999/switch'],
    'make a company default' => ['POST', '/settings/templates/99999999999999999999/company-active'],
    'pick a section template' => ['POST', '/rollcall/99999999999999999999/template'],
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

it('caps every id-like route parameter at 18 digits, so a new route cannot forget it', function () {
    $capped = ['unit', 'grant', 'trooper', 'version', 'template'];

    $uncapped = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => $route->parameterNames())
        ->unique()
        ->filter(fn (string $name) => in_array($name, ['id', 'user'], true) || str_ends_with($name, '_id') || str_ends_with($name, 'Id'))
        ->reject(fn (string $name) => in_array($name, $capped, true));

    expect($uncapped->all())->toBe([]);

    foreach ($capped as $name) {
        expect(Route::getPatterns())->toHaveKey($name, '[0-9]{1,18}');
    }
});

it('keeps the 18-digit cap on every route, so no route-level where() replaces it', function () {
    // The global patterns are merged into a route when it is created; a later `whereNumber()` or
    // `where()` on that route overwrites them. Check what each route actually matches with.
    $capped = ['unit', 'grant', 'trooper', 'version', 'template'];

    $loosened = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect($route->parameterNames())
            ->intersect($capped)
            ->reject(fn (string $name) => ($route->wheres[$name] ?? null) === '[0-9]{1,18}')
            ->map(fn (string $name) => $route->uri().' {'.$name.'} '.($route->wheres[$name] ?? '(none)')))
        ->values();

    expect($loosened->all())->toBe([]);
});
