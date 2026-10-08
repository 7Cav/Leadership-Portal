<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// The throwaway sample page (Story 1.1) is retired by Story 1.15: `/` is now only a doorway into
// the app, so it never renders anything of its own.
it('redirects a guest hitting / to the login screen', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects a signed-in user hitting / to home', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('home'));
});

it('prevents lazy loading outside production (ORM hygiene, seeds AD-2)', function () {
    // Test env runs as APP_ENV=testing (non-prod), so the guard must be ON.
    expect(Model::preventsLazyLoading())->toBeTrue();
});
