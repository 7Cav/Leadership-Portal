<?php

use Illuminate\Database\Eloquent\Model;
use Inertia\Testing\AssertableInertia;

it('boots and renders the sample page via Inertia', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sample')
            ->where('appName', config('app.name'))
            ->has('stack', 5)
        );
});

it('prevents lazy loading outside production (ORM hygiene, seeds AD-2)', function () {
    // Test env runs as APP_ENV=testing (non-prod), so the guard must be ON.
    expect(Model::preventsLazyLoading())->toBeTrue();
});
