<?php

use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\LoaSyncController;
use App\Http\Controllers\Settings\OrgStructureController;
use App\Http\Controllers\Settings\RosterSyncController;
use App\Http\Controllers\Settings\StaffGrantsController;
use App\Http\Controllers\ViewAsController;
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

    // Logout (Story 1.5). POST, so it carries CSRF protection and can't be triggered by a
    // prefetched link or a cross-site <img>.
    Route::post('/logout', [ForumAuthController::class, 'logout'])->name('logout');

    // View-as switcher (Story 1.13, AD-9). The picker reads its options as JSON; entering and
    // leaving are POST/DELETE so they carry CSRF protection. Every target is checked against real
    // authority by the ScopeResolver, here and again on every later request.
    Route::get('/view-as/options', [ViewAsController::class, 'options'])->name('view-as.options');
    Route::post('/view-as', [ViewAsController::class, 'store'])->name('view-as.store');
    Route::delete('/view-as', [ViewAsController::class, 'destroy'])->name('view-as.destroy');

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

    // Manual leave-sync trigger (Story 4.2): the same queued job the scheduler runs. Admin gate in the controller (AD-6).
    Route::post('/settings/loa-sync', [LoaSyncController::class, 'store'])->name('settings.loa-sync.store');
});
