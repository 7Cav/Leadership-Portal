<?php

use App\Http\Controllers\Auth\ForumAuthController;
use App\Http\Controllers\SampleController;
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
});
