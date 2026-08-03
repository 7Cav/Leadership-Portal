<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Current identity, read-only, minimal (Story 1.4). `->only()` on the
            // Authenticatable avoids a static App\Models\User reference, so this glue
            // layer stays terminal in the Deptrac graph.
            'auth' => [
                'user' => $request->user()?->only(['id', 'username', 'is_admin']),
                // Cav roster context for this login (Story 1.6): rank, billet and MOS, each
                // carrying the source and fetch time so the UI can never show them as if the
                // portal asserted them (AD-20 rule 1, NFR7). Null when enrichment did not
                // run or the trooper holds no milpac — the page degrades to the bare username.
                'profile' => $request->user() ? $request->session()->get('cav_profile') : null,
            ],
        ];
    }
}
