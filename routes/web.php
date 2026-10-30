<?php

use App\Http\Controllers\ActAsController;
use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\CounselingController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\GameIdentityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportRollupController;
use App\Http\Controllers\RollcallController;
use App\Http\Controllers\RollcallFireteamController;
use App\Http\Controllers\RollcallGuestController;
use App\Http\Controllers\RollcallTemplateController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\ScopeUnitsController;
use App\Http\Controllers\Settings\ActivityLogController;
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
use App\Http\Controllers\StaffPracticeController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\StaffReportsOverviewController;
use App\Http\Controllers\StrengthTrendsController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Controllers\TrainingReadinessMatrixController;
use App\Http\Controllers\TrooperDetailController;
use App\Http\Controllers\TrooperSearchController;
use App\Http\Controllers\UnitGuidonController;
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
foreach (['unit', 'grant', 'trooper', 'version', 'game', 'server', 'counseling', 'template', 'billet', 'entry'] as $id) {
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
    // controller (403 outside it); `{week}` is the Saturday that opens the Cav week. The draft autosave and the send
    // are audited, each with a bucket of its own (report-drafts, report-sends).
    Route::get('/reports/{unit}/{week}', [ReportController::class, 'show'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.show');
    Route::put('/reports/{unit}/{week}', [ReportController::class, 'draft'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:report-drafts')->name('reports.draft');
    Route::post('/reports/{unit}/{week}/send', [ReportController::class, 'send'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:report-sends')->name('reports.send');
    // The training topic a platoon sets for its sections this week. Who may set it (the platoon's
    // chain of command, or an Admin) is checked in the Command's handler. No `whereNumber('unit')`:
    // it would replace the 18-digit global pattern above.
    Route::put('/reports/{unit}/{week}/topic', [ReportController::class, 'topic'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:audited-writes')->name('reports.topic');
    Route::delete('/reports/{unit}/{week}/topic', [ReportController::class, 'clearTopic'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:audited-writes')->name('reports.topic.clear');

    // Monthly training plans (#380): on a platoon, its plan for `{month}` (YYYY-MM, this month when left out); above
    // one, the month's plans of every platoon beneath. Scope is checked in the controller (403 outside it); who may
    // write and who may review is checked in the Commands' handlers. Each write is audited, so they share the
    // audited-writes throttle.
    Route::get('/reports/{unit}/plans/{month?}', [TrainingPlanController::class, 'show'])
        ->where('month', '\d{4}-\d{2}')->name('training-plans.show');
    Route::put('/reports/{unit}/plans/{month}', [TrainingPlanController::class, 'save'])
        ->where('month', '\d{4}-\d{2}')->middleware('throttle:audited-writes')->name('training-plans.save');
    Route::post('/reports/{unit}/plans/{month}/approve', [TrainingPlanController::class, 'approve'])
        ->where('month', '\d{4}-\d{2}')->middleware('throttle:audited-writes')->name('training-plans.approve');
    Route::post('/reports/{unit}/plans/{month}/return', [TrainingPlanController::class, 'sendBack'])
        ->where('month', '\d{4}-\d{2}')->middleware('throttle:audited-writes')->name('training-plans.return');

    // The roster (Stories 2.8, 4.6, 4.7, 4.9): the selected unit's subtree, `?view=orbat|matrix`.
    // Scope-gated in the controller through the ScopeResolver (AD-8). The trooper Sheet reads its
    // detail as JSON; that route is declared first so `troopers` is never taken for a unit id.
    Route::get('/roster/troopers/{trooper}', [RosterController::class, 'trooper'])->name('roster.trooper');
    // A unit's strength week by week, scoped like the roster. Read only.
    Route::get('/roster/{unit}/strength', [StrengthTrendsController::class, 'show'])->name('roster.strength');
    Route::get('/roster/{unit?}', [RosterController::class, 'index'])->name('roster.index');
    // The shell's trooper search (JSON): troopers in the viewer's scope by username, for a jump to their milpac. It
    // offers only troopers the milpac below would open (ScopeResolver::canSee's rule), throttled per user.
    Route::get('/troopers/search', TrooperSearchController::class)->middleware('throttle:trooper-search')->name('troopers.search');
    // One trooper's milpac (Story 6.3): the page every entry point to a trooper opens. Scope-gated
    // in the controller through the ScopeResolver (AD-8).
    Route::get('/troopers/{trooper}', [TrooperDetailController::class, 'show'])->name('troopers.show');
    // Developmental Counseling Statements and Letters of Reprimand (#381). Each write is an audited Command whose handler
    // decides who may file which type and who may change a record (Authorization\CounselingAccess); a trooper or record
    // the viewer cannot see is a 404, the document download included. Filing stores a PDF of up to 10 MB, so it has its
    // own, tighter throttle (counseling-uploads); corrections, deletes and overturns share the audited-writes one.
    Route::post('/troopers/{trooper}/counseling', [CounselingController::class, 'store'])->middleware('throttle:counseling-uploads')->name('counseling.store');
    Route::patch('/counseling/{counseling}', [CounselingController::class, 'update'])->middleware('throttle:audited-writes')->name('counseling.update');
    Route::delete('/counseling/{counseling}', [CounselingController::class, 'destroy'])->middleware('throttle:audited-writes')->name('counseling.destroy');
    Route::post('/counseling/{counseling}/overturn', [CounselingController::class, 'overturn'])->middleware('throttle:audited-writes')->name('counseling.overturn');
    Route::get('/counseling/{counseling}/document', [CounselingController::class, 'document'])->name('counseling.document');
    // The section practices a staff member attended (#448): staff are on no section's rollcall, so the staff member, their
    // staff leader or an Admin enters one on the milpac. Each write is an audited Command whose handler decides who may
    // (Authorization\StaffPracticeAccess); a trooper the viewer cannot see is a 404. On the shared audited-writes throttle.
    Route::post('/troopers/{trooper}/staff-practices', [StaffPracticeController::class, 'store'])->middleware('throttle:audited-writes')->name('staff-practices.store');
    Route::post('/troopers/{trooper}/staff-practices/remove', [StaffPracticeController::class, 'destroy'])->middleware('throttle:audited-writes')->name('staff-practices.remove');
    // The weekly staff report of a platoon or company HQ staff member: one page per staff member and
    // week, read by them, the chain above their HQ and Admins; sent once, by the staff member alone, as an audited Command
    // (Authorization\StaffReportAccess). `/staff-report` opens the viewer's own for the week whose report is due.
    Route::get('/staff-report', [StaffReportController::class, 'own'])->name('staff-report.own');
    // The staff reports overview: every HQ staff member whose report the viewer reads, for one week (`?week=`).
    Route::get('/staff-reports', [StaffReportsOverviewController::class, 'index'])->name('staff-reports.index');
    Route::get('/staff-report/{trooper}/{week}', [StaffReportController::class, 'show'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->name('staff-report.show');
    Route::post('/staff-report/{trooper}/{week}', [StaffReportController::class, 'send'])
        ->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:audited-writes')->name('staff-report.send');

    // The training-readiness matrix of one unit (Story 6.9): troopers by courses, scope-gated in the controller
    // through the ScopeResolver (AD-8). The milpac's quick action links to the trooper's platoon. Like the roster's
    // `{unit?}` and the settings writes' ids below, the id is looked up in the controller after its gate, not by route
    // model binding, so a missing id and a forbidden one get the same answer.
    Route::get('/readiness/{unit}', [TrainingReadinessMatrixController::class, 'show'])->name('readiness.matrix');

    // Weekly rollcall of one section (Stories 3.7–3.10). Scope is enforced in the controller
    // through the ScopeResolver (AD-8): an out-of-scope section is a 403, not a hidden link. Every write here is
    // audited and throttled per user: the mark autosave on a generous bucket of its own (rollcall-marks), sized for
    // marking a whole section in seconds, and the rest on the shared audited-writes one.
    Route::get('/rollcall/{unit?}', [RollcallController::class, 'show'])->name('rollcall.show');
    Route::post('/rollcall/{unit}', [RollcallController::class, 'store'])->middleware('throttle:rollcall-marks')->name('rollcall.store');
    Route::post('/rollcall/{unit}/unmatched/link', [RollcallController::class, 'linkUnmatched'])->middleware('throttle:audited-writes')->name('rollcall.unmatched.link');
    Route::post('/rollcall/{unit}/unmatched/dismiss', [RollcallController::class, 'dismissUnmatched'])->middleware('throttle:audited-writes')->name('rollcall.unmatched.dismiss');
    Route::post('/rollcall/{unit}/unmatched/restore', [RollcallController::class, 'restoreUnmatched'])->middleware('throttle:audited-writes')->name('rollcall.unmatched.restore');
    Route::post('/rollcall/{unit}/capture', [RollcallController::class, 'retryCapture'])->middleware('throttle:practice-capture')->name('rollcall.capture');
    Route::post('/rollcall/{unit}/confirm', [RollcallController::class, 'confirm'])->middleware('throttle:audited-writes')->name('rollcall.confirm');
    Route::post('/rollcall/{unit}/posted', [RollcallController::class, 'posted'])->middleware('throttle:audited-writes')->name('rollcall.posted');
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
    // The section's pick of rollcall template (#375): an audited Command gated by the section's write scope.
    Route::post('/rollcall/{unit}/template', RollcallTemplateController::class)->middleware('throttle:audited-writes')->name('rollcall.template.pick');

    // Game identities (Story 7.2): link troopers in scope to their in-game identity, one per
    // game (`{platform}` is the game's slug, #388). The controller reads repositories; each write is an audited Command whose handler
    // checks the trooper against the viewer's scope (AD-8) — outside it is a 403. Both writes share the audited-writes
    // throttle.
    Route::get('/game-identities', [GameIdentityController::class, 'index'])->name('game-identities.index');
    Route::put('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'update'])->middleware('throttle:audited-writes')->name('game-identities.update');
    Route::delete('/game-identities/{trooper}/{platform}', [GameIdentityController::class, 'destroy'])->middleware('throttle:audited-writes')->name('game-identities.destroy');

    // The changelog: open to everyone signed in; only the changelog editors write it, each write audited and on the
    // shared audited-writes throttle. The import preview writes nothing but shares the throttle, as every POST does.
    Route::get('/changelog', [ChangelogController::class, 'index'])->name('changelog.index');
    Route::post('/changelog', [ChangelogController::class, 'store'])->middleware('throttle:audited-writes')->name('changelog.store');
    Route::post('/changelog/import/preview', [ChangelogController::class, 'preview'])->middleware('throttle:audited-writes')->name('changelog.import.preview');
    Route::post('/changelog/import', [ChangelogController::class, 'import'])->middleware('throttle:audited-writes')->name('changelog.import');
    Route::post('/changelog/outdated/dismiss', [ChangelogController::class, 'dismiss'])->middleware('throttle:audited-writes')->name('changelog.outdated.dismiss');
    Route::patch('/changelog/{entry}', [ChangelogController::class, 'update'])->middleware('throttle:audited-writes')->name('changelog.update');
    Route::delete('/changelog/{entry}', [ChangelogController::class, 'destroy'])->middleware('throttle:audited-writes')->name('changelog.destroy');

    // The in-portal guides (#409). Docs home and one guide, filtered to what the viewer may read (the controller asks
    // the ScopeResolver, so view-as applies); every docs route answers 404 while `portal.docs` is off. Guide ids are
    // slugs, never numbers. `/documentation` is an alias, sent on permanently with the rest of its path.
    Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/help', [DocsController::class, 'help'])->name('docs.help');
    Route::get('/docs/{section}/{slug}', [DocsController::class, 'show'])
        ->where(['section' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'])->name('docs.show');
    Route::permanentRedirect('/documentation', '/docs');
    Route::permanentRedirect('/documentation/{path}', '/docs/{path}')->where('path', '.*');

    // Logout (Story 1.5). POST, so it carries CSRF protection and can't be triggered by a
    // prefetched link or a cross-site <img>. Not throttled: it may record a view as or act as ending, but each one ends
    // the session, and signing back in is throttled per IP (AuditedWriteThrottleTest lists it as the one exception).
    Route::post('/logout', [ForumAuthController::class, 'logout'])->name('logout');

    // View-as switcher (Story 1.13, AD-9). The picker reads its options as JSON; entering and
    // leaving are POST/DELETE so they carry CSRF protection. Every target is checked against real
    // authority by the ScopeResolver, here and again on every later request. Entering and leaving are audited, so
    // they share the audited-writes throttle: a leader looping them cannot flood the audit log.
    Route::get('/view-as/options', [ViewAsController::class, 'options'])->name('view-as.options');

    // The sidebar's unit switcher (#426): the units in the viewer's scope, read when its menu opens. Navigation only;
    // an Admin's list is the whole regiment, so it has a small limit of its own.
    Route::get('/scope/units', ScopeUnitsController::class)->middleware('throttle:scope-units')->name('scope.units');
    Route::post('/view-as', [ViewAsController::class, 'store'])->middleware('throttle:audited-writes')->name('view-as.store');
    Route::delete('/view-as', [ViewAsController::class, 'destroy'])->middleware('throttle:audited-writes')->name('view-as.destroy');

    // Act as (#379, #432): an Admin, or a leader for the billet holders below them, acts as another person with that
    // person's permissions, writes included, under a red bar. The picker reads its options as JSON. Starting and
    // stopping are audited, so they share the audited-writes throttle. Only the real user behind the request may start
    // or stop it, and who may be acted as is checked on every request; the controller and ActAs check.
    // The picker's list is a heavy read (several queries per billet holder below the viewer), so it has a small limit
    // of its own.
    Route::get('/act-as/options', [ActAsController::class, 'options'])->middleware('throttle:act-as-options')->name('act-as.options');
    Route::post('/act-as', [ActAsController::class, 'store'])->middleware('throttle:audited-writes')->name('act-as.store');
    Route::delete('/act-as', [ActAsController::class, 'destroy'])->middleware('throttle:audited-writes')->name('act-as.destroy');

    // Report roll-up workspace (Story 5.8): the chain of the viewer's scope for one week
    // (`?week=YYYY-MM-DD`), scope-gated in the controller (AD-8). Read-only; each unit's own
    // report is `reports.show` at `/reports/{unit}/{week}`.
    Route::get('/reports/{unit?}', [ReportRollupController::class, 'index'])->name('reports.index');

    // Chain of command (Story 1.8, edit/delete in 1.9). Viewing is open to any authenticated
    // user (AC #3); every write is Admin-only, checked in the controller against the real
    // Admin rule, `ScopeResolver::isAdmin()` (the flag or an approved Regimental HQ billet, AD-6), not by route middleware. Every write is audited, so they share the audited-writes
    // throttle; the import reads Cav on the request, so it shares the roster sync's bucket instead.
    Route::get('/settings/org-structure', [OrgStructureController::class, 'index'])->name('settings.org-structure');
    Route::post('/settings/org-structure', [OrgStructureController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.org-structure.store');
    // Import the tree + billet mapping from the Cav position groups (Story 2.9). Admin gate in the
    // controller, as for every other write here.
    Route::post('/settings/org-structure/import', [OrgStructureController::class, 'import'])->middleware('throttle:roster-sync')->name('settings.org-structure.import');
    Route::patch('/settings/org-structure/{unit}', [OrgStructureController::class, 'update'])->middleware('throttle:audited-writes')->name('settings.org-structure.update');
    Route::patch('/settings/org-structure/{unit}/label', [OrgStructureController::class, 'label'])->middleware('throttle:audited-writes')->name('settings.org-structure.label');
    Route::patch('/settings/org-structure/{unit}/game', [OrgStructureController::class, 'game'])->middleware('throttle:audited-writes')->name('settings.org-structure.game');
    Route::post('/settings/org-structure/{unit}/acting-lead', [OrgStructureController::class, 'assignActingLead'])->middleware('throttle:audited-writes')->name('settings.org-structure.acting-lead.store');
    Route::delete('/settings/org-structure/{unit}/acting-lead', [OrgStructureController::class, 'removeActingLead'])->middleware('throttle:audited-writes')->name('settings.org-structure.acting-lead.destroy');
    // Who oversees a battalion or the Reservists: set or clear, Admin-only, audited.
    Route::post('/settings/org-structure/{unit}/oversight', [OrgStructureController::class, 'setOversight'])->middleware('throttle:audited-writes')->name('settings.org-structure.oversight.store');
    Route::delete('/settings/org-structure/{unit}/oversight', [OrgStructureController::class, 'clearOversight'])->middleware('throttle:audited-writes')->name('settings.org-structure.oversight.destroy');
    Route::delete('/settings/org-structure/{unit}', [OrgStructureController::class, 'destroy'])->middleware('throttle:audited-writes')->name('settings.org-structure.destroy');

    // Place a trooper the sync could not map into a section (Story 2.7). The Admin gate is checked in the
    // controller first and again in the Command's handler; the required reason and the audited write live
    // in the handler (AD-6, AD-16), not in the route.
    Route::post('/settings/org-structure/troopers/{trooper}/placement', [TrooperPlacementController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.trooper-placement.store');

    // Staff grants (Story 1.12). Viewing is open to any authenticated user, matching
    // org-structure; the Admin gate on every write is enforced in the controller (AD-6). Audited, so throttled like
    // every audited write.
    Route::get('/settings/staff-grants', [StaffGrantsController::class, 'index'])->name('settings.staff-grants');
    Route::post('/settings/staff-grants', [StaffGrantsController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.staff-grants.store');
    Route::delete('/settings/staff-grants/{grant}', [StaffGrantsController::class, 'destroy'])->middleware('throttle:audited-writes')->name('settings.staff-grants.destroy');
    // Confirming a Regimental HQ billet as an Admin billet, Admin only, audited.
    Route::post('/settings/staff-grants/admin-billets/{billet}/approve', [StaffGrantsController::class, 'approveAdminBillet'])->middleware('throttle:audited-writes')->name('settings.staff-grants.admin-billets.approve');
    Route::post('/settings/staff-grants/admin-billets/{billet}/reject', [StaffGrantsController::class, 'rejectAdminBillet'])->middleware('throttle:audited-writes')->name('settings.staff-grants.admin-billets.reject');

    // Rollcall template authoring (Story 3.5, #375, AD-17). Viewing is open to any authenticated user (the controller
    // shows a non-Admin only the companies in their scope); each write is an audited Command that checks the actor
    // commands the company, or the section for its own templates, or is an Admin for the regiment default. A save is always a new immutable version. Every
    // request writes an audit row, so they share the per-user throttle.
    Route::get('/settings/templates', [TemplatesController::class, 'index'])->name('settings.templates');
    Route::post('/settings/templates', [TemplatesController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.templates.store');
    Route::post('/settings/templates/new', [TemplatesController::class, 'create'])->middleware('throttle:audited-writes')->name('settings.templates.create');
    Route::post('/settings/templates/{version}/activate', [TemplatesController::class, 'activate'])->middleware('throttle:audited-writes')->name('settings.templates.activate');
    Route::post('/settings/templates/{template}/switch', [TemplatesController::class, 'switch'])->middleware('throttle:audited-writes')->name('settings.templates.switch');
    Route::post('/settings/templates/{template}/company-active', [TemplatesController::class, 'companyActive'])->middleware('throttle:audited-writes')->name('settings.templates.company-active');
    // One section's rollcall for this week, to fill the template preview with (scope-gated in the controller, AD-8).
    Route::get('/settings/templates/preview/{unit}', [TemplatesController::class, 'previewSection'])->name('settings.templates.preview');

    // The games the regiment plays (#388). Viewing is open to any authenticated user; the Admin gate on every write is
    // enforced in the controller (AD-6). A game in use is retired, not deleted. Each write is audited and shares the
    // audited-writes throttle.
    Route::get('/settings/games', [GamesController::class, 'index'])->name('settings.games');
    Route::post('/settings/games', [GamesController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.games.store');
    Route::patch('/settings/games/{game}', [GamesController::class, 'update'])->middleware('throttle:audited-writes')->name('settings.games.update');
    Route::post('/settings/games/{game}/retire', [GamesController::class, 'retire'])->middleware('throttle:audited-writes')->name('settings.games.retire');
    Route::delete('/settings/games/{game}/retire', [GamesController::class, 'reinstate'])->middleware('throttle:audited-writes')->name('settings.games.reinstate');
    Route::delete('/settings/games/{game}', [GamesController::class, 'destroy'])->middleware('throttle:audited-writes')->name('settings.games.destroy');

    // The game servers the telemetry APIs list (#389). The whole screen is Admin-only, gated in the controller (AD-6).
    // Fetching asks every interface over HTTP on the request, so it is capped per Admin like the manual syncs. Editing a
    // server is audited, so it shares the audited-writes throttle.
    Route::get('/settings/servers', [ServersController::class, 'index'])->name('settings.servers');
    Route::post('/settings/servers/fetch', [ServersController::class, 'fetch'])->middleware('throttle:server-fetch')->name('settings.servers.fetch');
    Route::patch('/settings/servers/{server}', [ServersController::class, 'update'])->middleware('throttle:audited-writes')->name('settings.servers.update');

    // Audit-log review (Story 1.14). The whole screen is Admin-only (AC #4) — the gate is
    // enforced in the controller, not route middleware, matching every other Admin gate here.
    Route::get('/settings/audit-log', [AuditLogController::class, 'index'])->name('settings.audit-log');

    // The activity log (#382): the pages each person opened, for retracing a bug report. Admin-only, gated in the
    // controller like the audit log. Read-only, so no throttle.
    Route::get('/settings/activity-log', [ActivityLogController::class, 'index'])->name('settings.activity-log');

    // Manual roster sync trigger (Story 2.4), posted by the roster screen's "Sync now" control
    // (2.8); there is no GET view of its own. The Admin gate is enforced in the controller (AD-6),
    // matching every other admin-gated write here. Throttled per Admin by the `roster-sync`
    // limiter; the job itself is unique, so extra clicks never stack up queued syncs.
    Route::post('/settings/roster-sync', [RosterSyncController::class, 'store'])->middleware('throttle:roster-sync')->name('settings.roster-sync.store');

    // An echelon leader's manual sync: the same queued job, writing only that unit's subtree. The leader gate is in
    // the controller (ScopeResolver::commandsUnit). The throttle caps each user; the controller's cooldown caps syncs of a unit and the units above and below it, since each run is a full Cav fetch.
    Route::post('/units/{unit}/roster-sync', [UnitRosterSyncController::class, 'store'])->middleware('throttle:unit-roster-sync')->name('units.roster-sync.store');

    // A company's, battalion's or the regiment's guidon (#446), saved from the roster's generator. Who may (the unit's
    // command, the command above it, or an Admin) is checked in the Command's handler (ScopeResolver::commandsUnit).
    // Each save writes an audit row, so it shares the audited-writes throttle.
    Route::put('/units/{unit}/guidon', [UnitGuidonController::class, 'update'])->middleware('throttle:audited-writes')->name('units.guidon.update');

    // Manual leave-sync trigger (Story 4.2): the same queued job the scheduler runs. Admin gate in the controller (AD-6).
    // Throttled per Admin by the `loa-sync` limiter, like the roster trigger.
    Route::post('/settings/loa-sync', [LoaSyncController::class, 'store'])->middleware('throttle:loa-sync')->name('settings.loa-sync.store');

    // Demo history for the real roster (#115). Admin-only; every route 404s unless
    // `portal.demo_tools` is on (DEMO_TOOLS=true) — both gates are enforced in the controller. Generating and purging
    // are audited, so they share the audited-writes throttle.
    Route::get('/settings/demo-data', [DemoDataController::class, 'index'])->name('settings.demo-data');
    Route::post('/settings/demo-data', [DemoDataController::class, 'store'])->middleware('throttle:audited-writes')->name('settings.demo-data.store');
    Route::delete('/settings/demo-data', [DemoDataController::class, 'destroy'])->middleware('throttle:audited-writes')->name('settings.demo-data.destroy');
});

// Browser-test sign-in (Story 5.8). Only the loopback Playwright server using its dedicated
// throwaway SQLite file and a fresh test-browser cookie may sign in by username. Check
// these at request time too: a cached route table or an accidental APP_ENV=e2e on a
// public deployment must not bypass SSO.
if (app()->environment('e2e')) {
    Route::get('/__e2e/login/{username}', function (string $username) {
        $database = (string) config('database.connections.sqlite.database');
        $resolved = realpath($database);
        $testToken = (string) config('portal.e2e_login_token');
        abort_unless(
            app()->environment('e2e')
                && in_array(request()->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
                && config('database.default') === 'sqlite'
                && blank(config('database.connections.sqlite.url'))
                && $resolved !== false
                && $resolved === realpath(database_path('e2e.sqlite'))
                && $testToken !== ''
                && hash_equals($testToken, (string) request()->cookie('e2e_login_token')),
            404,
        );

        $user = app(UserRepository::class)->withUsername($username)->sole();
        auth()->login($user);
        request()->session()->regenerate();

        // A path on this app only: `to=//evil.example` must not become an off-site redirect.
        return redirect('/'.ltrim((string) request()->query('to', 'home'), '/'));
    });
}
