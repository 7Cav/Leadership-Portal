<?php

namespace App\Bookmarks;

use App\Authorization\PageAccess;
use App\Authorization\PageDecision;
use App\Authorization\ScopeResolver;
use App\Docs\DocsPages;
use App\Docs\ViewerAudience;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\TrooperRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The pages a person may bookmark, and whether they may still open one. A bookmark is a route name on this allowlist
 * and its parameters, never a raw address. Each route maps to the one rule its page's controller enforces
 * ({@see PageAccess}, or `DocsPages` for the docs), so a bookmark is shown exactly while the page would open.
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
        private readonly PageAccess $access,
        private readonly TrooperRepository $troopers,
        private readonly DocsPages $docs,
        private readonly ViewerAudience $audience,
    ) {}

    /**
     * Every route that can be bookmarked.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return [...array_keys(self::UNIT_PAGES), 'troopers.show', 'docs.show', ...array_keys(self::FIXED_PAGES)];
    }

    /**
     * The routes that have a rule in {@see checks()}, to hold against {@see routes()}.
     *
     * @return list<string>
     */
    public function checkedRoutes(): array
    {
        return array_keys($this->checks(new User, fn () => collect()));
    }

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
     * @param  array<int|string, array{route: string, params: array<string, int|string>}>  $pages
     * @return array<int|string, ?array{label: string, href: string}>
     */
    public function openable(User $user, array $pages): array
    {
        $scope = null;
        $scopeOf = function () use (&$scope, $user): Collection {
            return $scope ??= $this->scope->resolve($user);
        };
        $checks = $this->checks($user, $scopeOf);

        $answers = [];
        foreach ($pages as $key => $page) {
            $check = $checks[$page['route']] ?? null;
            $label = $check === null ? null : $check($page['params']);
            $answers[$key] = $label === null ? null : ['label' => $label, 'href' => route($page['route'], $page['params'], false)];
        }

        return $answers;
    }

    /**
     * Each bookmarkable route's rule: the page's label while `$user` may open it, else null. Every one asks the rule
     * the page's controller enforces and decides nothing of its own.
     *
     * @param  callable(): Collection<int, int>  $scopeOf
     * @return array<string, callable(array<string, int|string>): ?string>
     */
    private function checks(User $user, callable $scopeOf): array
    {
        $unit = fn (string $route, callable $decide) => function (array $params) use ($route, $decide): ?string {
            /** @var PageDecision $decision */
            $decision = $decide((int) $params['unit']);

            return $decision->allowed() ? self::UNIT_PAGES[$route].' '.($decision->subject->callsign ?? $decision->subject->name) : null;
        };
        $fixed = fn (string $route, callable $decide) => fn (array $params): ?string => $decide()->allowed() ? self::FIXED_PAGES[$route] : null;

        return [
            'roster.index' => $unit('roster.index', fn (int $id) => $this->access->roster($user, $id, $scopeOf())),
            'reports.index' => $unit('reports.index', fn (int $id) => $this->access->reports($user, $id, $scopeOf())),
            'rollcall.show' => $unit('rollcall.show', fn (int $id) => $this->access->rollcall($user, $id, $scopeOf())),
            'readiness.matrix' => $unit('readiness.matrix', fn (int $id) => $this->access->readiness($user, $id, $scopeOf())),
            'troopers.show' => function (array $params) use ($user, $scopeOf): ?string {
                $trooper = $this->troopers->find((int) $params['trooper']);

                return $trooper !== null && $this->access->milpac($user, $trooper, $scopeOf())->allowed() ? $trooper->display_name : null;
            },
            'docs.show' => fn (array $params): ?string => DocsPages::enabled()
                ? $this->docs->readable($this->audience->of($user), (string) $params['section'], (string) $params['slug'])?->title
                : null,
            'docs.index' => fn (array $params): ?string => DocsPages::enabled() ? self::FIXED_PAGES['docs.index'] : null,
            'staff-reports.index' => $fixed('staff-reports.index', fn () => $this->access->staffReports($user)),
            'settings.org-structure' => $fixed('settings.org-structure', fn () => $this->access->openSetting($user)),
            'settings.staff-grants' => $fixed('settings.staff-grants', fn () => $this->access->openSetting($user)),
            'settings.templates' => $fixed('settings.templates', fn () => $this->access->openSetting($user)),
            'settings.games' => $fixed('settings.games', fn () => $this->access->openSetting($user)),
            'settings.servers' => $fixed('settings.servers', fn () => $this->access->adminSetting($user)),
            'settings.audit-log' => $fixed('settings.audit-log', fn () => $this->access->adminSetting($user)),
            'settings.activity-log' => $fixed('settings.activity-log', fn () => $this->access->adminSetting($user)),
            'settings.demo-data' => $fixed('settings.demo-data', fn () => $this->access->demoData($user)),
        ];
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
