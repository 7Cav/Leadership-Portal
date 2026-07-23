<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Throwaway controller proving the Controller -> Inertia::render -> Vue wire (Story 1.1, AC-2).
 * Under the slimmed Laravel-idiomatic layer model, a controller shapes its own trivial props
 * inline — no read-service ceremony. Removed once a real page lands.
 */
class SampleController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Sample', [
            'appName' => config('app.name'),
            'stack' => ['Laravel 13', 'Inertia 3', 'Vue 3.5', 'Tailwind 4', 'MySQL 8.4'],
        ]);
    }
}
