<?php

use App\Models\User;

/**
 * A background `fetch` (the rollcall autosave, the trooper Sheet) sends `Accept: application/json`
 * and gets its errors as JSON with the real status, so it can tell an ended session from a refusal.
 * Inertia visits keep Inertia's own handling: a redirect to sign in, an error page.
 */
it('answers a signed-out fetch with a 401, not a redirect to the login page', function () {
    $this->getJson('/view-as/options')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('answers a refused fetch with a JSON 403 carrying the reason', function () {
    $this->actingAs(User::factory()->serving()->create())
        ->postJson('/settings/loa-sync')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('still sends a signed-out Inertia visit to sign in', function () {
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
        ->get('/home')
        ->assertRedirect(route('login'));
});

it('still sends a signed-out Inertia visit that asks for anything to sign in', function () {
    // `expectsJson()` is true for an XHR that accepts `*/*`; an Inertia visit must not be caught by that.
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])
        ->get('/home')
        ->assertRedirect(route('login'));
});
