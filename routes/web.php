<?php

use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\OrgStructureController;
use App\Http\Controllers\Settings\StaffGrantsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Throwaway skeleton page (Story 1.1). Kept so the 1.3 smoke tests stay green;
// retired once real pages take over.
Route::get('/', [SampleController::class, 'show']);

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
    Route::get('/home', fn () => Inertia::render('Home'))->name('home');

    // Logout (Story 1.5). POST, so it carries CSRF protection and can't be triggered by a
    // prefetched link or a cross-site <img>.
    Route::post('/logout', [ForumAuthController::class, 'logout'])->name('logout');

    // Chain of command (Story 1.8, edit/delete in 1.9). Viewing is open to any authenticated
    // user (AC #3); the Admin gate on every write is enforced in the controller, not by route
    // middleware — there is no `can:` policy for it yet (Authorization/ScopeResolver lands in
    // 1.11).
    Route::get('/settings/org-structure', [OrgStructureController::class, 'index'])->name('settings.org-structure');
    Route::post('/settings/org-structure', [OrgStructureController::class, 'store'])->name('settings.org-structure.store');
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
});
