<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

// The app shell (Story 1.15) shows the Settings group only when `auth.user.is_admin` is exactly
// `true`. The Vitest suite mocks that prop, so this pins the real one HandleInertiaRequests shares.

it('shares is_admin as a real boolean on every shell page', function (bool $isAdmin) {
    $this->actingAs(User::factory()->create(['is_admin' => $isAdmin]))
        ->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.is_admin', $isAdmin)
            ->has('auth.user.username')
        );
})->with(['an Admin' => true, 'a non-Admin' => false]);
