<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\ChangelogAccess;
use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Kpi\Headcount;
use App\Repositories\AdminBilletRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\StaffReportRepository;
use App\Repositories\UnitGuidonRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use App\Tutorial\TutorialSandbox;
use App\Tutorial\TutorialSession;
use App\Tutorial\TutorialSteps;
use Carbon\CarbonImmutable;
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
        private readonly ChangelogAccess $changelog,
        private readonly UnitGuidonRepository $guidons,
        private readonly TutorialSandbox $sandbox,
        private readonly TutorialSession $tutorial,
        private readonly TutorialSteps $steps,
        private readonly WeeklyReportRepository $weeklyReports,
        private readonly StaffReportRepository $sentStaffReports,
        private readonly NotificationRepository $notifications,
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
                // Admin billet (ScopeResolver::actsAsAdmin()), worked out on this request
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
            // How many Admin billets wait for an Admin to confirm them, for the
            // sidebar's notice on Staff grants. Only to someone acting as Admin; zero for everyone else.
            // Whether the sidebar shows Settings > Templates to someone who is not an Admin: anyone who may manage some
            // unit's roll call templates, by the rule the template writes use. Not during a view-as preview, which never
            // writes. Navigation only: the page and each write check for themselves.
            'canManageTemplates' => fn () => $request->user() !== null
                && $this->scope->activeViewAs($request->user()) === null
                && $this->scope->managesAnyTemplates($request->user()),
            // Whether the sidebar shows Settings > Org structure to someone who is not an Admin: Regimental HQ staff, who
            // set each battalion's accountability options there. Navigation only: the page checks for itself.
            'canSetBattalionAccountability' => fn () => $request->user() !== null
                && $this->scope->activeViewAs($request->user()) === null
                && $this->scope->isRegimentalHqStaff($request->user()),
            'adminBilletsPending' => fn () => $request->user() && $this->scope->actsAsAdmin($request->user()) ? $this->adminBillets->pendingCount() : 0,
            // `actAs`: whether the banner offers to act as the preview's target, which only a real Admin may (the
            // server checks again on the start).
            'viewAs' => fn () => $request->user() && ($preview = $this->scope->activeViewAs($request->user())) !== null
                ? [...$preview->toArray(), 'actAs' => $this->scope->isAdmin($request->user())]
                : null,
            // The top unit(s) of the viewer's scope as this request sees it (view as and act as included), which the
            // sidebar's unit switcher and the breadcrumb start from (#426). The switcher's full list is read only when
            // it opens (`/scope/units`).
            // Each top carries the guidon the switcher shows in the crest spot: its own, or its nearest ancestor's (a
            // section leader sees their company's), read in one query.
            'scopeTop' => function () use ($request) {
                if ($request->user() === null) {
                    return [];
                }
                $tops = $this->scope->topUnits($request->user());
                $guidons = $this->guidons->designsFor($tops, inherit: true);

                return array_map(
                    fn (array $top) => [...$top, 'guidon' => $guidons[$top['id']] ?? null],
                    $this->headcount->scopeLabelTops($tops),
                );
            },
            // The hands-on tutorial, while this request runs in it (RunInTutorial): its steps with their checklists ticked
            // from the person's own practice copy, for the blue bar and the coach card. Null outside the tutorial.
            'tutorialMode' => fn () => $this->sandbox->entered() && ($persona = $this->tutorial->persona()) !== null ? [
                'steps' => $this->steps->describe((int) $this->sandbox->userId(), $persona),
            ] : null,
            // The 7cav.us forum's address, for links that open a forum page (the makeup PM opens the forum's own
            // "start conversation" form). The portal never writes to the forum; these are plain links.
            'forum' => [
                'baseUrl' => rtrim((string) config('services.sevencav.base_url'), '/'),
            ],
            // The changelog: `changelogUnseen` puts the sidebar's dot on Changelog while an entry was written after this
            // user last opened it; `changelogOutdated` shows a changelog editor, signed in as themselves, the blue
            // "Changelog outdated" bar after a redeploy nobody has written an entry for or dismissed.
            'changelogUnseen' => fn () => $request->user() !== null && $this->changelog->hasUnseen($request->user()),
            'changelogOutdated' => fn () => $request->user() !== null && $this->changelog->showsOutdated($request->user()),
            // The header's bell: how many of the user's own notifications are unread, or null to hide the bell during a
            // view-as preview, while an Admin acts as someone, or in the tutorial, whose database is the practice copy
            // (the list is read when the bell opens, `/notifications`).
            'notificationsUnread' => fn () => $request->user() === null || $this->acting($request) || $this->sandbox->entered() || $this->scope->activeViewAs($request->user()) !== null
                ? null
                : $this->notifications->unreadCountFor($request->user()->id),
            // A one-line outcome a write flashed for the next render (`notice`), which the shell shows as a toast: what
            // happened to a request whose result is not on the page yet, like a leave sync queued behind a running one.
            'flash' => fn () => ['notice' => $request->hasSession() ? $request->session()->get('notice') : null],
            // The reports this user still owes for the week that just ended, for the shell's due banner: from the Saturday
            // the week ends until its 2359Z deadline, only while unsent. Null outside that window, and while viewing as or
            // acting as someone, so the banner is only ever about the signed-in user's own reports.
            'reportsDue' => fn () => $this->reportsDue($request),
            // Optional tools the shell's navigation shows only when switched on (#115: Settings →
            // Demo data, DEMO_TOOLS=true; #409: the docs, DOCS_ENABLED=true). Navigation only — each
            // screen enforces its own gate.
            'features' => [
                'demoTools' => (bool) config('portal.demo_tools'),
                'docs' => (bool) config('portal.docs'),
                // The sidebar's staff report item: `overview` ("Staff reports") for whoever reads some HQ staff's
                // reports, `own` ("Staff report") for a platoon, company or battalion HQ staff member who reads only
                // their own.
                'staffReports' => $request->user() === null ? null : $this->staffReports->navFor($request->user()),
            ],
        ];
    }

    /**
     * The weekly report of each line unit the user commands (billet or acting lead, the units whose report they send)
     * and their own staff report (the rule Home's reminder uses), unsent for the week that just ended. Null when none.
     *
     * @return array{due: string, reports: list<array{kind: string, label: ?string, href: string}>}|null
     */
    private function reportsDue(Request $request): ?array
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $weekStart = WeekOf::lastEnded($now);
        $due = WeekOf::reportDue($weekStart);

        if ($user === null || $now->greaterThan($due) || $this->acting($request) || $this->scope->activeViewAs($user) !== null) {
            return null;
        }

        $week = $weekStart->toDateString();
        $units = $this->scope->commandedBy($user)->filter(fn ($unit) => $unit->isLine())->values();
        // One query for the week's reports; a reopened report is a draft again, so it is owed.
        $sent = $units->isEmpty() ? collect() : $this->weeklyReports->forUnits($units->pluck('id')->all(), $weekStart)
            ->filter(fn ($report) => $report->status->value === 'sent');
        $reports = $units
            ->reject(fn ($unit) => $sent->has($unit->id))
            ->map(fn ($unit) => [
                'kind' => 'unit',
                'label' => $unit->callsign ?? $unit->name,
                'href' => route('reports.show', ['unit' => $unit->id, 'week' => $week]),
            ])
            ->values();

        $trooper = $this->staffReports->ownTrooper($user);
        if ($trooper !== null && $this->staffReports->owes($trooper, $weekStart) !== null && $this->sentStaffReports->find($trooper->id, $weekStart) === null) {
            $reports->push([
                'kind' => 'staff',
                'label' => null,
                'href' => route('staff-report.show', ['trooper' => $trooper->id, 'week' => $week]),
            ]);
        }

        return $reports->isEmpty() ? null : ['due' => $due->toIso8601String(), 'reports' => $reports->all()];
    }

    private function acting(Request $request): bool
    {
        return $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) !== null;
    }
}
