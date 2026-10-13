<?php

use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\GameIdentityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportRollupController;
use App\Http\Controllers\RollcallController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\DemoDataController;
use App\Http\Controllers\Settings\LoaSyncController;
use App\Http\Controllers\Settings\OrgStructureController;
use App\Http\Controllers\Settings\RosterSyncController;
use App\Http\Controllers\Settings\StaffGrantsController;
use App\Http\Controllers\Settings\TrooperPlacementController;
use App\Http\Controllers\TrooperDetailController;
use App\Http\Controllers\ViewAsController;
use App\Http\Middleware\EnsureServingMember;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Route;

// Every id in a path is a positive integer of at most 18 digits, for every route that names it.
// `whereNumber` alone (`[0-9]+`) let `/reports/99999999999999999999` through to an `int`
// parameter, which PHP cannot hold, so the request died in a TypeError and a 500 instead of a
// 404. 18 digits always fit in a signed 64-bit int. Declared once as global patterns so a new
// route taking one of these ids cannot forget the constraint (RouteIdConstraintTest walks the
// routes and fails on an id-like parameter missing from this list).
foreach (['unit', 'grant', 'trooper'] as $id) {
    Route::pattern($id, '[0-9]{1,18}');
}

// `/` is only a doorway (Story 1.15 retired the Story 1.1 sample page): signed-in users land on
// home, everyone else on the login screen, in one hop either way.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'login'));

// 7Cav SSO (Story 1.4). `login` is the name Laravel's auth middleware redirects to.
// Login screen + starting the handshake are guest-only; the callback stays public so
// it can always complete the OAuth2 round-trip. Both handshake routes run under the `login`
// rate limiter (AppServiceProvider): each hit costs a session write or a forum token exchange.
Route::middleware('guest')->group(function () {
    Route::get('/login', [ForumAuthController::class, 'show'])->name('login');
    Route::get('/auth/redirect', [ForumAuthController::class, 'redirect'])->middleware('throttle:login')->name('auth.redirect');
});
// Path matches the redirect URI registered for the forum OAuth client (confirmed in 1.4).
Route::get('/oauth/callback/7cav', [ForumAuthController::class, 'callback'])->middleware('throttle:login')->name('auth.callback');

// Authenticated landing. Unauthenticated hits redirect to `login` via the auth
// middleware. Named `home` — the target Laravel's guest middleware bounces to.
Route::middleware(['auth', EnsureServingMember::class])->group(function () {
    Route::get('/home', [HomeController::class, 'show'])->name('home');

    // The weekly report for one unit-week (Stories 5.3–5.5): compose a section's draft, or a
    // non-leaf unit's roll-up; save the draft; freeze and send it. Scope is checked in the
    // controller (403 outside it); `{week}` is the Saturday that opens the Cav week.
    Route::get('/reports/{unit}/{week}', [ReportController::class, 'show'])
        ->whereNumber('unit')->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.show');
    Route::put('/reports/{unit}/{week}', [ReportController::class, 'draft'])
        ->whereNumber('unit')->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.draft');
    Route::post('/reports/{unit}/{week}/send', [ReportController::class, 'send'])
        ->whereNumber('unit')->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.send');

    // The roster (Stories 2.8, 4.6, 4.7, 4.9): the selected unit's subtree, `?view=orbat|matrix`.
    // Scope-gated in the controller through the ScopeResolver (AD-8). The trooper Sheet reads its
    // detail as JSON; that route is declared first so `troopers` is never taken for a unit id.
    Route::get('/roster/troopers/{trooper}', [RosterController::class, 'trooper'])->name('roster.trooper');
    Route::get('/roster/{unit?}', [RosterController::class, 'index'])->name('roster.index');
    // One trooper's milpac (Story 6.3): the page every entry point to a trooper opens. Scope-gated
    // in the controller through the ScopeResolver (AD-8).
    Route::get('/troopers/{trooper}', [TrooperDetailController::class, 'show'])->name('troopers.show');

    // Weekly rollcall of one section (Stories 3.7–3.10). Scope is enforced in the controller
    // through the ScopeResolver (AD-8): an out-of-scope section is a 403, not a hidden link.
    Route::get('/rollcall/{unit?}', [RollcallController::class, 'show'])->whereNumber('unit')->name('rollcall.show');
    Route::post('/rollcall/{unit}', [RollcallController::class, 'store'])->whereNumber('unit')->name('rollcall.store');
    Route::post('/rollcall/{unit}/unmatched/link', [RollcallController::class, 'linkUnmatched'])->whereNumber('unit')->name('rollcall.unmatched.link');
    Route::post('/rollcall/{unit}/unmatched/dismiss', [RollcallController::class, 'dismissUnmatched'])->whereNumber('unit')->name('rollcall.unmatched.dismiss');
    Route::post('/rollcall/{unit}/capture', [RollcallController::class, 'retryCapture'])->whereNumber('unit')->name('rollcall.capture');
    Route::post('/rollcall/{unit}/confirm', [RollcallController::class, 'confirm'])->whereNumber('unit')->name('rollcall.confirm');
    Route::post('/rollcall/{unit}/posted', [RollcallController::class, 'posted'])->whereNumber('unit')->name('rollcall.posted');

    // Game identities (Story 7.2): link troopers in scope to their in-game identity, one per
    // platform. The controller reads repositories; each write is an audited Command whose handler
    // checks the trooper against the viewer's scope (AD-8) — outside it is a 403.
    Route::get('/game-identities', [GameIdentityController::class, 'index'])->name('game-identities.index');
    Route::put('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'update'])
        ->whereNumber('trooper')->name('game-identities.update');
    Route::delete('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'destroy'])
        ->whereNumber('trooper')->name('game-identities.destroy');

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
    Route::get('/reports/{unit?}', [ReportRollupController::class, 'index'])->name('reports.index');

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

    // Place a trooper the sync could not map into a section (Story 2.7). The Admin gate is checked in the
    // controller first and again in the Command's handler; the required reason and the audited write live
    // in the handler (AD-6, AD-16), not in the route. The id is capped at 18 digits rather than
    // `whereNumber()`: a longer one overflows the controller's `int` parameter and would answer 500, not 404.
    Route::post('/settings/org-structure/troopers/{trooper}/placement', [TrooperPlacementController::class, 'store'])->where('trooper', '[0-9]{1,18}')->name('settings.trooper-placement.store');

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
    // matching every other admin-gated write here. Throttled per Admin by the `roster-sync`
    // limiter; the job itself is unique, so extra clicks never stack up queued syncs.
    Route::post('/settings/roster-sync', [RosterSyncController::class, 'store'])->middleware('throttle:roster-sync')->name('settings.roster-sync.store');

    // Manual leave-sync trigger (Story 4.2): the same queued job the scheduler runs. Admin gate in the controller (AD-6).
    Route::post('/settings/loa-sync', [LoaSyncController::class, 'store'])->name('settings.loa-sync.store');

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
