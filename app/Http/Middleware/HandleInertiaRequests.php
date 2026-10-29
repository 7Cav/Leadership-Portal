<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Kpi\Headcount;
use App\Repositories\AdminBilletRepository;
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

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ActAs $actAs,
        private readonly Headcount $headcount,
        private readonly AdminBilletRepository $adminBillets,
        private readonly StaffReportAccess $staffReports,
    ) {}

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
                // `is_admin` is whether the shell shows this request's user as an Admin, by the flag or by an approved
                // Admin billet (ScopeResolver::actsAsAdmin(), policy, 2026-10-28), worked out on this request
                // and never the stored column alone. While an Admin acts as someone it answers for who the request runs
                // as; during a view-as preview, for whoever is previewed, as every page does. Display only: the server's
                // gates ask the ScopeResolver themselves.
                'user' => $request->user() ? [
                    ...$request->user()->only(['id', 'username']),
                    'is_admin' => $this->scope->actsAsAdmin($request->user()),
                ] : null,
                // Cav roster context for this login (Story 1.6): rank, billet and MOS, each
                // carrying the source and fetch time so the UI can never show them as if the
                // portal asserted them (AD-20 rule 1, NFR7). Null when enrichment did not
                // run or the trooper holds no milpac — the page degrades to the bare username.
                // While an Admin acts as someone (#379) the session's profile is the Admin's own, so none is shown.
                'profile' => $request->user() && ! $this->acting($request) ? $request->session()->get('cav_profile') : null,
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
            // An Admin acting as someone (#379): who, for the red bar, and who the Admin really is.
            // `kind` is `user` for a person, `unit` for a billet (a unit's leader, no person behind it).
            'actingAs' => fn () => $this->acting($request) ? [
                'kind' => $request->attributes->get(ActAs::ACTING_ATTRIBUTE)->billet === null ? 'user' : 'unit',
                'label' => $request->attributes->get(ActAs::ACTING_ATTRIBUTE)->label,
                'admin' => $request->attributes->get(ActAs::ADMIN_ATTRIBUTE)->username,
            ] : null,
            // Whether the shell offers the act-as picker (#432): a leader with people below them, or an Admin acting as
            // one. Navigation only: every start is checked again by ActAs.
            'canActAs' => fn () => $request->user() ? $this->actAs->offers($request->attributes->get(ActAs::ADMIN_ATTRIBUTE) ?? $request->user()) : false,
            // How many Admin billets wait for an Admin to confirm them (policy, 2026-10-28), for the
            // sidebar's notice on Staff grants. Only to someone acting as Admin; zero for everyone else.
            'adminBilletsPending' => fn () => $request->user() && $this->scope->actsAsAdmin($request->user()) ? $this->adminBillets->pendingCount() : 0,
            // `actAs`: whether the banner offers to act as the preview's target, which only a real Admin may (the
            // server checks again on the start).
            'viewAs' => fn () => $request->user() && ($preview = $this->scope->activeViewAs($request->user())) !== null
                ? [...$preview->toArray(), 'actAs' => $this->scope->isAdmin($request->user())]
                : null,
            // The top unit(s) of the viewer's scope as this request sees it (view as and act as included), which the
            // sidebar's unit switcher and the breadcrumb start from (#426). The switcher's full list is read only when
            // it opens (`/scope/units`).
            'scopeTop' => fn () => $request->user() ? $this->headcount->scopeLabelTops($this->scope->topUnits($request->user())) : [],
            // The 7cav.us forum's address, for links that open a forum page (the makeup PM opens the forum's own
            // "start conversation" form). The portal never writes to the forum; these are plain links.
            'forum' => [
                'baseUrl' => rtrim((string) config('services.sevencav.base_url'), '/'),
            ],
            // A one-line outcome a write flashed for the next render (`notice`), which the shell shows as a toast: what
            // happened to a request whose result is not on the page yet, like a leave sync queued behind a running one.
            'flash' => fn () => ['notice' => $request->hasSession() ? $request->session()->get('notice') : null],
            // Optional tools the shell's navigation shows only when switched on (#115: Settings →
            // Demo data, DEMO_TOOLS=true; #409: the docs, DOCS_ENABLED=true). Navigation only — each
            // screen enforces its own gate.
            'features' => [
                'demoTools' => (bool) config('portal.demo_tools'),
                'docs' => (bool) config('portal.docs'),
                // "Staff report": for a platoon or company HQ staff member signed in as themselves.
                'staffReport' => $request->user() !== null && $this->staffReports->isHqStaffNow($request->user()),
            ],
        ];
    }

    private function acting(Request $request): bool
    {
        return $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) !== null;
    }
}
