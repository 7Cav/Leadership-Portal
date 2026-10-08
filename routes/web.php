<?php

use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportRollupController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\DemoDataController;
use App\Http\Controllers\Settings\OrgStructureController;
use App\Http\Controllers\Settings\RosterSyncController;
use App\Http\Controllers\Settings\StaffGrantsController;
use App\Http\Controllers\ViewAsController;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Route;

// `/` is only a doorway (Story 1.15 retired the Story 1.1 sample page): signed-in users land on
// home, everyone else on the login screen, in one hop either way.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'login'));

// 7Cav SSO (Story 1.4). `login` is the name Laravel's auth middleware redirects to.
// Login screen + starting the handshake are guest-only; the callback stays public so
// it can always complete the OAuth2 round-trip.
Route::middleware('guest')->group(function () {
    Route::get('/login', [ForumAuthController::class, 'show'])->name('login');
    Route::get('/auth/redirect', [ForumAuthController::class, 'redirect'])->name('auth.redirect');
});
// Path matches the redirect URI registered for the forum OAuth client (confirmed in 1.4).
Route::get('/oauth/callback/7cav', [ForumAuthController::class, 'callback'])->name('auth.callback');

// Authenticated landing. Unauthenticated hits redirect to `login` via the auth
// middleware. Named `home` — the target Laravel's guest middleware bounces to.
Route::middleware('auth')->group(function () {
    Route::get('/home', [HomeController::class, 'show'])->name('home');

    // The roster (Stories 2.8, 4.6, 4.7, 4.9): the selected unit's subtree, `?view=orbat|matrix`.
    // Scope-gated in the controller through the ScopeResolver (AD-8). The trooper Sheet reads its
    // detail as JSON; that route is declared first so `troopers` is never taken for a unit id.
    Route::get('/roster/troopers/{trooper}', [RosterController::class, 'trooper'])->whereNumber('trooper')->name('roster.trooper');
    Route::get('/roster/{unit?}', [RosterController::class, 'index'])->whereNumber('unit')->name('roster.index');

    // Logout (Story 1.5). POST, so it carries CSRF protection and can't be triggered by a
    // prefetched link or a cross-site <img>.
    Route::post('/logout', [ForumAuthController::class, 'logout'])->name('logout');

    // View-as switcher (Story 1.13, AD-9). The picker reads its options as JSON; entering and
    // leaving are POST/DELETE so they carry CSRF protection. Every target is checked against real
    // authority by the ScopeResolver, here and again on every later request.
    Route::get('/view-as/options', [ViewAsController::class, 'options'])->name('view-as.options');
    Route::post('/view-as', [ViewAsController::class, 'store'])->name('view-as.store');
    Route::delete('/view-as', [ViewAsController::class, 'destroy'])->name('view-as.destroy');

    // Report roll-up workspace (Story 5.8): the chain of the viewer's scope for one week
    // (`?week=YYYY-MM-DD`), scope-gated in the controller (AD-8). Read-only; each unit's own
    // report is `reports.show` at `/reports/{unit}/{week}`.
    Route::get('/reports/{unit?}', [ReportRollupController::class, 'index'])->whereNumber('unit')->name('reports.index');

    // Chain of command (Story 1.8, edit/delete in 1.9). Viewing is open to any authenticated
    // user (AC #3); the Admin gate on every write is enforced in the controller, not by route
    // middleware — there is no `can:` policy for it yet (Authorization/ScopeResolver lands in
    // 1.11).
    Route::get('/settings/org-structure', [OrgStructureController::class, 'index'])->name('settings.org-structure');
    Route::post('/settings/org-structure', [OrgStructureController::class, 'store'])->name('settings.org-structure.store');
    // Import the tree + billet mapping from the Cav position groups (Story 2.9). Admin gate in the
    // controller, as for every other write here.
    Route::post('/settings/org-structure/import', [OrgStructureController::class, 'import'])->name('settings.org-structure.import');
    Route::patch('/settings/org-structure/{unit}', [OrgStructureController::class, 'update'])->name('settings.org-structure.update');
    Route::delete('/settings/org-structure/{unit}', [OrgStructureController::class, 'destroy'])->name('settings.org-structure.destroy');

    // Staff grants (Story 1.12). Viewing is open to any authenticated user, matching
    // org-structure; the Admin gate on every write is enforced in the controller (AD-6).
    Route::get('/settings/staff-grants', [StaffGrantsController::class, 'index'])->name('settings.staff-grants');
    Route::post('/settings/staff-grants', [StaffGrantsController::class, 'store'])->name('settings.staff-grants.store');
    Route::delete('/settings/staff-grants/{grant}', [StaffGrantsController::class, 'destroy'])->name('settings.staff-grants.destroy');

    // Audit-log review (Story 1.14). The whole screen is Admin-only (AC #4) — the gate is
    // enforced in the controller, not route middleware, matching every other Admin gate here.
    Route::get('/settings/audit-log', [AuditLogController::class, 'index'])->name('settings.audit-log');

    // Manual roster sync trigger (Story 2.4). No GET view yet — the roster screen that will
    // host this control is Story 2.8's. The Admin gate is enforced in the controller (AD-6),
    // matching every other admin-gated write here.
    Route::post('/settings/roster-sync', [RosterSyncController::class, 'store'])->name('settings.roster-sync.store');

    // Demo history for the real roster (#115). Admin-only; every route 404s unless
    // `portal.demo_tools` is on (DEMO_TOOLS=true) — both gates are enforced in the controller.
    Route::get('/settings/demo-data', [DemoDataController::class, 'index'])->name('settings.demo-data');
    Route::post('/settings/demo-data', [DemoDataController::class, 'store'])->name('settings.demo-data.store');
    Route::delete('/settings/demo-data', [DemoDataController::class, 'destroy'])->name('settings.demo-data.destroy');
});

// Browser-test sign-in (Story 5.8). The real login is a 7cav.us OAuth round-trip a test cannot
// drive, so the Playwright server — and only it, running as APP_ENV=e2e against its own throwaway
// SQLite database — signs a seeded user in by username. Never registered in any other environment.
if (app()->environment('e2e')) {
    Route::get('/__e2e/login/{username}', function (string $username) {
        $user = app(UserRepository::class)->withUsername($username)->sole();
        auth()->login($user);
        request()->session()->regenerate();

        // A path on this app only: `to=//evil.example` must not become an off-site redirect.
        return redirect('/'.ltrim((string) request()->query('to', 'home'), '/'));
    });
}
