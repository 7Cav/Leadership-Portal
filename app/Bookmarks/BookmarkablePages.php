<?php

namespace App\Bookmarks;

use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Docs\GuideLibrary;
use App\Docs\ViewerAudience;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The pages a person may bookmark, and whether they may still open one. A bookmark is a route name on this allowlist
 * and its parameters, never a raw address. Each page is checked by the same rule its controller gates on, through the
 * same ScopeResolver, StaffReportAccess and GuideLibrary calls, so a bookmark is shown exactly while the page would
 * open for the person. Change a rule here together with the controller it names.
 *
 * A unit's weekly report is bookmarked on the unit (`reports.index`), not on one week, so it always opens the current
 * week: a section's report, or the roll-up above one.
 */
final class BookmarkablePages
{
    /**
     * The request attribute a page sets to the unit it shows when its address names none (the roster opened from the
     * sidebar lands on the viewer's own unit), so that page can still be bookmarked.
     */
    public const PAGE_UNIT = 'bookmarks.page_unit';

    /** Pages about one unit (`{unit}`), and the word their label starts with. */
    private const UNIT_PAGES = [
        'roster.index' => 'Roster',
        'reports.index' => 'Report',
        'rollcall.show' => 'Roll call',
        'readiness.matrix' => 'Readiness',
    ];

    /** Pages with no parameters, and their label. */
    private const FIXED_PAGES = [
        'staff-reports.index' => 'Staff reports',
        'docs.index' => 'Docs',
        'settings.org-structure' => 'Org structure',
        'settings.staff-grants' => 'Staff grants',
        'settings.templates' => 'Templates',
        'settings.games' => 'Games',
        'settings.servers' => 'Servers',
        'settings.audit-log' => 'Audit log',
        'settings.activity-log' => 'Activity log',
        'settings.demo-data' => 'Demo data',
    ];

    /** The docs routes' section and slug (routes/web.php). */
    private const SLUG = '/^[a-z0-9-]{1,64}$/';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly StaffReportAccess $staffReports,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly GuideLibrary $guides,
        private readonly ViewerAudience $audience,
    ) {}

    /**
     * `$params` for `$route` as stored: only the parameters the page takes, ids as integers. Null when the route is not
     * on the allowlist or the parameters are not the page's.
     *
     * @param  array<string, mixed>  $params
     * @return ?array<string, int|string>
     */
    public function normalize(string $route, array $params): ?array
    {
        if (isset(self::FIXED_PAGES[$route])) {
            return $params === [] ? [] : null;
        }

        if (isset(self::UNIT_PAGES[$route])) {
            return array_keys($params) === ['unit'] && ($id = self::id($params['unit'])) !== null ? ['unit' => $id] : null;
        }

        if ($route === 'troopers.show') {
            return array_keys($params) === ['trooper'] && ($id = self::id($params['trooper'])) !== null ? ['trooper' => $id] : null;
        }

        if ($route === 'docs.show') {
            $keys = array_keys($params);
            sort($keys);

            return $keys === ['section', 'slug'] && self::slug($params['section']) && self::slug($params['slug'])
                ? ['section' => $params['section'], 'slug' => $params['slug']]
                : null;
        }

        return null;
    }

    /**
     * The page this request shows, as a bookmark: `route` and `params`, or null when it is not one that can be
     * bookmarked. A report week opens the unit's report (`reports.index`); a query (`?week=`, `?view=`) is not kept.
     * Whether the person may open it is not checked here ({@see openable()}).
     *
     * @return ?array{route: string, params: array<string, int|string>}
     */
    public function current(Request $request): ?array
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($name === null) {
            return null;
        }

        $params = collect($route->parameters())
            ->map(fn ($value) => $value instanceof Trooper ? $value->id : $value)
            ->all();

        if ($name === 'reports.show') {
            $name = 'reports.index';
            unset($params['week']);
        }

        if ($name === 'roster.index' && ($params['unit'] ?? null) === null) {
            $page = $request->attributes->get(self::PAGE_UNIT);
            $params = $page === null ? [] : ['unit' => $page];
        }

        $params = array_filter($params, fn ($value) => $value !== null);
        $normalized = $this->normalize($name, $params);

        return $normalized === null ? null : ['route' => $name, 'params' => $normalized];
    }

    /**
     * For each page, keyed as given, its label and address while `$user` may open it, else null. The scope is read
     * once for all of them.
     *
     * @param  array<int, array{route: string, params: array<string, int|string>}>  $pages
     * @return array<int, ?array{label: string, href: string}>
     */
    public function openable(User $user, array $pages): array
    {
        $scope = null;
        $scopeOf = function () use (&$scope, $user): Collection {
            return $scope ??= $this->scope->resolve($user);
        };
        $readsStaffReports = null;

        $answers = [];
        foreach ($pages as $key => $page) {
            $label = $this->label($user, $page['route'], $page['params'], $scopeOf, $readsStaffReports);
            $answers[$key] = $label === null ? null : ['label' => $label, 'href' => route($page['route'], $page['params'], false)];
        }

        return $answers;
    }

    /**
     * The page's label while `$user` may open it, by the rule of the controller named; null when they may not.
     *
     * @param  array<string, int|string>  $params
     * @param  callable(): Collection<int, int>  $scopeOf
     */
    private function label(User $user, string $route, array $params, callable $scopeOf, ?bool &$readsStaffReports): ?string
    {
        if (isset(self::UNIT_PAGES[$route])) {
            $unit = $this->unit($route, (int) $params['unit'], $scopeOf);

            return $unit === null ? null : self::UNIT_PAGES[$route].' '.($unit->callsign ?? $unit->name);
        }

        return match ($route) {
            // TrooperDetailController::show.
            'troopers.show' => ($trooper = $this->troopers->find((int) $params['trooper'])) !== null
                && ($this->scope->canSee($user, $trooper, $scopeOf()) || $this->scope->isOwnTrooper($user, $trooper))
                    ? $trooper->display_name
                    : null,
            // StaffReportsOverviewController::index.
            'staff-reports.index' => ($readsStaffReports ??= $this->staffReports->readableHqs($user)->isNotEmpty()) ? self::FIXED_PAGES[$route] : null,
            // DocsController::index and ::show.
            'docs.index' => (bool) config('portal.docs') ? self::FIXED_PAGES[$route] : null,
            'docs.show' => (bool) config('portal.docs')
                ? $this->guides->find($this->audience->of($user), (string) $params['section'], (string) $params['slug'])?->title
                : null,
            // Open to everyone signed in: OrgStructureController, StaffGrantsController, TemplatesController and
            // GamesController each show a non-Admin what is theirs to see.
            'settings.org-structure', 'settings.staff-grants', 'settings.templates', 'settings.games' => self::FIXED_PAGES[$route],
            // ServersController, AuditLogController and ActivityLogController: Admin only.
            'settings.servers', 'settings.audit-log', 'settings.activity-log' => $this->scope->actsAsAdmin($user) ? self::FIXED_PAGES[$route] : null,
            // DemoDataController: Admin only, while the demo tools are on.
            'settings.demo-data' => (bool) config('portal.demo_tools') && $this->scope->actsAsAdmin($user) ? self::FIXED_PAGES[$route] : null,
            default => null,
        };
    }

    /**
     * The unit a unit page shows while the scope holds it, by its controller's rule: any unit for the roster
     * (RosterController::index) and the readiness matrix (TrainingReadinessMatrixController), a line unit for the
     * report (ReportRollupController::index), a section for the roll call (RollcallController::show).
     *
     * @param  callable(): Collection<int, int>  $scopeOf
     */
    private function unit(string $route, int $id, callable $scopeOf): ?Unit
    {
        $unit = $scopeOf()->contains($id) ? $this->units->find($id) : null;

        return match (true) {
            $unit === null => null,
            $route === 'reports.index' => $unit->isLine() ? $unit : null,
            $route === 'rollcall.show' => $unit->type === UnitType::Section ? $unit : null,
            default => $unit,
        };
    }

    /** A positive id of at most 18 digits (the routes' id pattern), from a number or its digits. */
    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : null;
    }

    private static function slug(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SLUG, $value) === 1;
    }
}
