<?php

use App\Http\Controllers\ActAsController;
use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\GameIdentityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportRollupController;
use App\Http\Controllers\RollcallController;
use App\Http\Controllers\RollcallFireteamController;
use App\Http\Controllers\RollcallGuestController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\DemoDataController;
use App\Http\Controllers\Settings\GamesController;
use App\Http\Controllers\Settings\LoaSyncController;
use App\Http\Controllers\Settings\OrgStructureController;
use App\Http\Controllers\Settings\RosterSyncController;
use App\Http\Controllers\Settings\ServersController;
use App\Http\Controllers\Settings\StaffGrantsController;
use App\Http\Controllers\Settings\TemplatesController;
use App\Http\Controllers\Settings\TrooperPlacementController;
use App\Http\Controllers\TrainingReadinessMatrixController;
use App\Http\Controllers\TrooperDetailController;
use App\Http\Controllers\TrooperSearchController;
use App\Http\Controllers\UnitRosterSyncController;
use App\Http\Controllers\ViewAsController;
use App\Http\Middleware\EnsureServingMember;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Route;

// Every id in a path is a positive integer of at most 18 digits, for every route that names it.
// `whereNumber` alone (`[0-9]+`) let `/reports/99999999999999999999` through to an `int`
// parameter, which PHP cannot hold, so the request died in a TypeError and a 500 instead of a
// 404. 18 digits always fit in a signed 64-bit int. Declared once as global patterns so a new
// route taking one of these ids cannot forget the constraint (RouteIdConstraintTest walks the
// routes and fails on an id-like parameter missing from this list). Don't add `whereNumber()` or
// `where()` for these on a route: it replaces the global pattern rather than adding to it.
foreach (['unit', 'grant', 'trooper', 'version', 'game', 'server'] as $id) {
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
// Path matches the redirect URI registered for the forum OAuth client (`{APP_URL}/auth/callback`).
Route::get('/auth/callback', [ForumAuthController::class, 'callback'])->middleware('throttle:login')->name('auth.callback');

// Authenticated landing. Unauthenticated hits redirect to `login` via the auth
// middleware. Named `home` — the target Laravel's guest middleware bounces to.
Route::middleware(['auth', EnsureServingMember::class])->group(function () {
    Route::get('/home', [HomeController::class, 'show'])->name('home');

    // The weekly report for one unit-week (Stories 5.3–5.5): compose a section's draft, or a
    // non-leaf unit's roll-up; save the draft; freeze and send it. Scope is checked in the
    // controller (403 outside it); `{week}` is the Saturday that opens the Cav week.
    Route::get('/reports/{unit}/{week}', [ReportController::class, 'show'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.show');
    Route::put('/reports/{unit}/{week}', [ReportController::class, 'draft'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.draft');
    Route::post('/reports/{unit}/{week}/send', [ReportController::class, 'send'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.send');
    // The training topic a platoon sets for its sections this week. Who may set it (the platoon's
    // chain of command, or an Admin) is checked in the Command's handler. No `whereNumber('unit')`:
    // it would replace the 18-digit global pattern above.
    Route::put('/reports/{unit}/{week}/topic', [ReportController::class, 'topic'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:audited-writes')->name('reports.topic');
    Route::delete('/reports/{unit}/{week}/topic', [ReportController::class, 'clearTopic'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:audited-writes')->name('reports.topic.clear');

    // The roster (Stories 2.8, 4.6, 4.7, 4.9): the selected unit's subtree, `?view=orbat|matrix`.
    // Scope-gated in the controller through the ScopeResolver (AD-8). The trooper Sheet reads its
    // detail as JSON; that route is declared first so `troopers` is never taken for a unit id.
    Route::get('/roster/troopers/{trooper}', [RosterController::class, 'trooper'])->name('roster.trooper');
    Route::get('/roster/{unit?}', [RosterController::class, 'index'])->name('roster.index');
    // The shell's trooper search (JSON): troopers in the viewer's scope by username, for a jump to their milpac. It
    // offers only troopers the milpac below would open (ScopeResolver::canSee's rule), throttled per user.
    Route::get('/troopers/search', TrooperSearchController::class)->middleware('throttle:trooper-search')->name('troopers.search');
    // One trooper's milpac (Story 6.3): the page every entry point to a trooper opens. Scope-gated
    // in the controller through the ScopeResolver (AD-8).
    Route::get('/troopers/{trooper}', [TrooperDetailController::class, 'show'])->name('troopers.show');

    // The training-readiness matrix of one unit (Story 6.9): troopers by courses, scope-gated in the controller
    // through the ScopeResolver (AD-8). The milpac's quick action links to the trooper's platoon. Like the roster's
    // `{unit?}` and the settings writes' ids below, the id is looked up in the controller after its gate, not by route
    // model binding, so a missing id and a forbidden one get the same answer.
    Route::get('/readiness/{unit}', [TrainingReadinessMatrixController::class, 'show'])->name('readiness.matrix');

    // Weekly rollcall of one section (Stories 3.7–3.10). Scope is enforced in the controller
    // through the ScopeResolver (AD-8): an out-of-scope section is a 403, not a hidden link.
    Route::get('/rollcall/{unit?}', [RollcallController::class, 'show'])->name('rollcall.show');
    Route::post('/rollcall/{unit}', [RollcallController::class, 'store'])->name('rollcall.store');
    Route::post('/rollcall/{unit}/unmatched/link', [RollcallController::class, 'linkUnmatched'])->name('rollcall.unmatched.link');
    Route::post('/rollcall/{unit}/unmatched/dismiss', [RollcallController::class, 'dismissUnmatched'])->middleware('throttle:audited-writes')->name('rollcall.unmatched.dismiss');
    Route::post('/rollcall/{unit}/unmatched/restore', [RollcallController::class, 'restoreUnmatched'])->middleware('throttle:audited-writes')->name('rollcall.unmatched.restore');
    Route::post('/rollcall/{unit}/capture', [RollcallController::class, 'retryCapture'])->middleware('throttle:practice-capture')->name('rollcall.capture');
    Route::post('/rollcall/{unit}/confirm', [RollcallController::class, 'confirm'])->name('rollcall.confirm');
    Route::post('/rollcall/{unit}/posted', [RollcallController::class, 'posted'])->name('rollcall.posted');
    // Fireteams on the rollcall (the owner's order, 2026-10-16): each write is an audited Command whose handler checks
    // the actor commands the section (or, for the policy, its company); a staff grant reads, it does not write. These,
    // the unmatched dismiss and restore, the topic and the guest add and remove share one per-user throttle, since each
    // request writes an audit row.
    Route::post('/rollcall/{unit}/fireteams', [RollcallFireteamController::class, 'role'])->middleware('throttle:audited-writes')->name('rollcall.fireteams.role');
    Route::post('/rollcall/{unit}/fireteams/options', [RollcallFireteamController::class, 'options'])->middleware('throttle:audited-writes')->name('rollcall.fireteams.options');
    Route::post('/rollcall/{unit}/fireteams/policy', [RollcallFireteamController::class, 'policy'])->middleware('throttle:audited-writes')->name('rollcall.fireteams.policy');
    // Guests at this week's practice (JSAM evidence, decided 2026-10-23): a host leader adds a trooper from another
    // section, or takes back one they added. Each write is an audited Command whose handler checks the section's write
    // scope; the picker finds serving troopers outside the leader's scope by username, answering only name, rank and
    // section, throttled per user like the shell's trooper search.
    Route::get('/rollcall/{unit}/guests/search', [RollcallGuestController::class, 'search'])->middleware('throttle:trooper-search')->name('rollcall.guests.search');
    Route::post('/rollcall/{unit}/guests', [RollcallGuestController::class, 'store'])->middleware('throttle:audited-writes')->name('rollcall.guests.store');
    Route::post('/rollcall/{unit}/guests/remove', [RollcallGuestController::class, 'destroy'])->middleware('throttle:audited-writes')->name('rollcall.guests.remove');

    // Game identities (Story 7.2): link troopers in scope to their in-game identity, one per
    // game (`{platform}` is the game's slug, #388). The controller reads repositories; each write is an audited Command whose handler
    // checks the trooper against the viewer's scope (AD-8) — outside it is a 403.
    Route::get('/game-identities', [GameIdentityController::class, 'index'])->name('game-identities.index');
    Route::put('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'update'])->name('game-identities.update');
    Route::delete('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'destroy'])->name('game-identities.destroy');

    // Logout (Story 1.5). POST, so it carries CSRF protection and can't be triggered by a
    // prefetched link or a cross-site <img>.
    Route::post('/logout', [ForumAuthController::class, 'logout'])->name('logout');

    // View-as switcher (Story 1.13, AD-9). The picker reads its options as JSON; entering and
    // leaving are POST/DELETE so they carry CSRF protection. Every target is checked against real
    // authority by the ScopeResolver, here and again on every later request. Entering and leaving are audited, so
    // they share the audited-writes throttle: a leader looping them cannot flood the audit log.
    Route::get('/view-as/options', [ViewAsController::class, 'options'])->name('view-as.options');
    Route::post('/view-as', [ViewAsController::class, 'store'])->middleware('throttle:audited-writes')->name('view-as.store');
    Route::delete('/view-as', [ViewAsController::class, 'destroy'])->middleware('throttle:audited-writes')->name('view-as.destroy');

    // Act as (#379, #432): an Admin, or a leader for the billet holders below them, acts as another person with that
    // person's permissions, writes included, under a red bar. The picker reads its options as JSON. Starting and
    // stopping are audited, so they share the audited-writes throttle. Only the real user behind the request may start
    // or stop it, and who may be acted as is checked on every request; the controller and ActAs check.
    Route::get('/act-as/options', [ActAsController::class, 'options'])->name('act-as.options');
    Route::post('/act-as', [ActAsController::class, 'store'])->middleware('throttle:audited-writes')->name('act-as.store');
    Route::delete('/act-as', [ActAsController::class, 'destroy'])->middleware('throttle:audited-writes')->name('act-as.destroy');

    // Report roll-up workspace (Story 5.8): the chain of the viewer's scope for one week
    // (`?week=YYYY-MM-DD`), scope-gated in the controller (AD-8). Read-only; each unit's own
    // report is `reports.show` at `/reports/{unit}/{week}`.
    Route::get('/reports/{unit?}', [ReportRollupController::class, 'index'])->name('reports.index');

    // Chain of command (Story 1.8, edit/delete in 1.9). Viewing is open to any authenticated
    // user (AC #3); every write is Admin-only, checked in the controller against the real
    // `is_admin` flag (AD-6), not by route middleware.
    Route::get('/settings/org-structure', [OrgStructureController::class, 'index'])->name('settings.org-structure');
    Route::post('/settings/org-structure', [OrgStructureController::class, 'store'])->name('settings.org-structure.store');
    // Import the tree + billet mapping from the Cav position groups (Story 2.9). Admin gate in the
    // controller, as for every other write here.
    Route::post('/settings/org-structure/import', [OrgStructureController::class, 'import'])->name('settings.org-structure.import');
    Route::patch('/settings/org-structure/{unit}', [OrgStructureController::class, 'update'])->name('settings.org-structure.update');
    Route::patch('/settings/org-structure/{unit}/label', [OrgStructureController::class, 'label'])->name('settings.org-structure.label');
    Route::post('/settings/org-structure/{unit}/acting-lead', [OrgStructureController::class, 'assignActingLead'])->name('settings.org-structure.acting-lead.store');
    Route::delete('/settings/org-structure/{unit}/acting-lead', [OrgStructureController::class, 'removeActingLead'])->name('settings.org-structure.acting-lead.destroy');
    Route::delete('/settings/org-structure/{unit}', [OrgStructureController::class, 'destroy'])->name('settings.org-structure.destroy');

    // Place a trooper the sync could not map into a section (Story 2.7). The Admin gate is checked in the
    // controller first and again in the Command's handler; the required reason and the audited write live
    // in the handler (AD-6, AD-16), not in the route.
    Route::post('/settings/org-structure/troopers/{trooper}/placement', [TrooperPlacementController::class, 'store'])->name('settings.trooper-placement.store');

    // Staff grants (Story 1.12). Viewing is open to any authenticated user, matching
    // org-structure; the Admin gate on every write is enforced in the controller (AD-6).
    Route::get('/settings/staff-grants', [StaffGrantsController::class, 'index'])->name('settings.staff-grants');
    Route::post('/settings/staff-grants', [StaffGrantsController::class, 'store'])->name('settings.staff-grants.store');
    Route::delete('/settings/staff-grants/{grant}', [StaffGrantsController::class, 'destroy'])->name('settings.staff-grants.destroy');

    // Rollcall template authoring (Story 3.5, AD-17). Viewing is open to any authenticated user; the Admin gate on
    // every write is enforced in the controller (AD-6). A save is always a new immutable version.
    Route::get('/settings/templates', [TemplatesController::class, 'index'])->name('settings.templates');
    Route::post('/settings/templates', [TemplatesController::class, 'store'])->name('settings.templates.store');
    Route::post('/settings/templates/{version}/activate', [TemplatesController::class, 'activate'])->name('settings.templates.activate');
    // One section's rollcall for this week, to fill the template preview with (scope-gated in the controller, AD-8).
    Route::get('/settings/templates/preview/{unit}', [TemplatesController::class, 'previewSection'])->name('settings.templates.preview');

    // The games the regiment plays (#388). Viewing is open to any authenticated user; the Admin gate on every write is
    // enforced in the controller (AD-6). A game in use is retired, not deleted.
    Route::get('/settings/games', [GamesController::class, 'index'])->name('settings.games');
    Route::post('/settings/games', [GamesController::class, 'store'])->name('settings.games.store');
    Route::patch('/settings/games/{game}', [GamesController::class, 'update'])->name('settings.games.update');
    Route::post('/settings/games/{game}/retire', [GamesController::class, 'retire'])->name('settings.games.retire');
    Route::delete('/settings/games/{game}/retire', [GamesController::class, 'reinstate'])->name('settings.games.reinstate');
    Route::delete('/settings/games/{game}', [GamesController::class, 'destroy'])->name('settings.games.destroy');

    // The game servers the telemetry APIs list (#389). The whole screen is Admin-only, gated in the controller (AD-6).
    // Fetching asks every interface over HTTP on the request, so it is capped per Admin like the manual syncs.
    Route::get('/settings/servers', [ServersController::class, 'index'])->name('settings.servers');
    Route::post('/settings/servers/fetch', [ServersController::class, 'fetch'])->middleware('throttle:server-fetch')->name('settings.servers.fetch');
    Route::patch('/settings/servers/{server}', [ServersController::class, 'update'])->name('settings.servers.update');

    // Audit-log review (Story 1.14). The whole screen is Admin-only (AC #4) — the gate is
    // enforced in the controller, not route middleware, matching every other Admin gate here.
    Route::get('/settings/audit-log', [AuditLogController::class, 'index'])->name('settings.audit-log');

    // Manual roster sync trigger (Story 2.4), posted by the roster screen's "Sync now" control
    // (2.8); there is no GET view of its own. The Admin gate is enforced in the controller (AD-6),
    // matching every other admin-gated write here. Throttled per Admin by the `roster-sync`
    // limiter; the job itself is unique, so extra clicks never stack up queued syncs.
    Route::post('/settings/roster-sync', [RosterSyncController::class, 'store'])->middleware('throttle:roster-sync')->name('settings.roster-sync.store');

    // An echelon leader's manual sync: the same queued job, writing only that unit's subtree. The leader gate is in
    // the controller (ScopeResolver::commandsUnit). The throttle caps each user; the controller's cooldown caps syncs of a unit and the units above and below it, since each run is a full Cav fetch.
    Route::post('/units/{unit}/roster-sync', [UnitRosterSyncController::class, 'store'])->middleware('throttle:unit-roster-sync')->name('units.roster-sync.store');

    // Manual leave-sync trigger (Story 4.2): the same queued job the scheduler runs. Admin gate in the controller (AD-6).
    // Throttled per Admin by the `loa-sync` limiter, like the roster trigger.
    Route::post('/settings/loa-sync', [LoaSyncController::class, 'store'])->middleware('throttle:loa-sync')->name('settings.loa-sync.store');

    // Demo history for the real roster (#115). Admin-only; every route 404s unless
    // `portal.demo_tools` is on (DEMO_TOOLS=true) — both gates are enforced in the controller.
    Route::get('/settings/demo-data', [DemoDataController::class, 'index'])->name('settings.demo-data');
    Route::post('/settings/demo-data', [DemoDataController::class, 'store'])->name('settings.demo-data.store');
    Route::delete('/settings/demo-data', [DemoDataController::class, 'destroy'])->name('settings.demo-data.destroy');
});

// Browser-test sign-in (Story 5.8). The real login is a 7cav.us OAuth round-trip a test cannot
// drive, so the Playwright server — and only it, running as APP_ENV=e2e against its own throwaway
// SQLite database — signs a seeded user in by username. Never registered in any other environment,
// and the closure checks again, so a route cache built under e2e and deployed elsewhere still refuses.
if (app()->environment('e2e')) {
    Route::get('/__e2e/login/{username}', function (string $username) {
        abort_unless(app()->environment('e2e'), 404);

        $user = app(UserRepository::class)->withUsername($username)->sole();
        auth()->login($user);
        request()->session()->regenerate();

        // A path on this app only: `to=//evil.example` must not become an off-site redirect.
        return redirect('/'.ltrim((string) request()->query('to', 'home'), '/'));
    });
}
