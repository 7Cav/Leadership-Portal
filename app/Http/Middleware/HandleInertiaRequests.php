<?php

namespace App\Http\Middleware;

use App\Authorization\ScopeResolver;
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

    public function __construct(private readonly ScopeResolver $scope) {}

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
                // Why it is absent, for Story 1.7's degraded messaging. The literal keys here
                // duplicate ForumAuthController's constants because Deptrac lets Middleware reach
                // only Authorization, never a Controller, so a feature test asserts each prop against a real login —
                // renaming a constant without updating this line turns that test red.
                'profileUnavailable' => $request->user()
                    ? $request->session()->get('cav_profile_unavailable')
                    : null,
            ],
            // The active view-as preview (Story 1.13), re-validated against real authority on this
            // request (AD-9) — the shell's banner reads it. A stored target that no longer holds
            // is dropped by the resolver, so the banner and the scope never disagree.
            'viewAs' => fn () => $request->user() ? $this->scope->activeViewAs($request->user())?->toArray() : null,
            // The 7cav.us forum's address, for links that open a forum page (the makeup PM opens the forum's own
            // "start conversation" form). The portal never writes to the forum; these are plain links.
            'forum' => [
                'baseUrl' => rtrim((string) config('services.sevencav.base_url'), '/'),
            ],
            // Optional tools the shell's navigation shows only when switched on (#115: Settings →
            // Demo data, DEMO_TOOLS=true). Navigation only — each screen enforces its own gate.
            'features' => [
                'demoTools' => (bool) config('portal.demo_tools'),
            ],
        ];
    }
}
