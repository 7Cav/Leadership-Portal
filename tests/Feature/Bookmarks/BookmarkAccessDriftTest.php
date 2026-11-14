<?php

use App\Bookmarks\BookmarkablePages;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A bookmark is offered and kept exactly while its page opens. For every bookmarkable route and a spread of viewers,
// BookmarkablePages' verdict must match what a real GET of the page answers: it opens (200, or a redirect onward)
// or it refuses (403 or 404). A route added to the allowlist without a rule, or without a row here, fails too.

/**
 * The pages to try for each route, as `[route, params]`, from the shared fixture.
 *
 * @return list<array{0: string, 1: array<string, int|string>}>
 */
function driftPages(array $tree): array
{
    $u = $tree['units'];
    $t = $tree['troopers'];
    $unitPages = fn (string $route, array $units) => array_map(fn ($unit) => [$route, ['unit' => $unit]], $units);
    $ids = fn (array $names) => [...array_map(fn ($name) => $u[$name]->id, $names), 999999];

    return [
        ...$unitPages('roster.index', $ids(['host', 'other', 'platoon', 'company', 'eloa'])),
        ...$unitPages('reports.index', $ids(['host', 'platoon', 'company', 'eloa'])),
        ...$unitPages('rollcall.show', $ids(['host', 'other', 'platoon'])),
        ...$unitPages('readiness.matrix', $ids(['host', 'platoon', 's6'])),
        ...array_map(fn ($trooper) => ['troopers.show', ['trooper' => $trooper->id]], [$t['rifleman'], $t['psg'], $t['eloa'], $t['s6Member']]),
        ['docs.show', ['section' => 'basics', 'slug' => 'bookmarks']],
        ['docs.show', ['section' => 'admin', 'slug' => 'org-structure']],
        ['docs.show', ['section' => 'basics', 'slug' => 'not-a-guide']],
        ['docs.index', []],
        ['staff-reports.index', []],
        ['settings.org-structure', []],
        ['settings.staff-grants', []],
        ['settings.templates', []],
        ['settings.games', []],
        ['settings.servers', []],
        ['settings.audit-log', []],
        ['settings.activity-log', []],
        ['settings.demo-data', []],
    ];
}

beforeEach(function () {
    $this->tree = staffPracticeTree();
    config(['portal.docs' => true, 'portal.demo_tools' => true]);

    // The verdict is read inside a request, so view as and the rest of the request's state apply as on a page.
    Route::middleware(['web', 'auth'])->get('/__test/bookmark-verdicts', function () {
        $pages = json_decode((string) request()->query('pages'), true);

        return response()->json(array_map(
            fn (?array $page) => $page !== null,
            app(BookmarkablePages::class)->openable(request()->user(), array_map(fn ($p) => ['route' => $p[0], 'params' => $p[1]], $pages)),
        ));
    });
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('gives every bookmarkable route one rule and a row in this test', function () {
    $pages = app(BookmarkablePages::class);
    $routes = $pages->routes();
    sort($routes);
    $checked = $pages->checkedRoutes();
    sort($checked);
    $tried = collect(driftPages($this->tree))->pluck(0)->unique()->sort()->values()->all();

    expect($checked)->toBe($routes)
        ->and($tried)->toBe($routes);
    foreach ($routes as $route) {
        expect(Route::has($route))->toBeTrue("{$route} is not a route");
    }
});

it('agrees with the page itself for each viewer', function (string $who) {
    $users = $this->tree['users'];
    $viewer = match ($who) {
        'rifleman' => User::factory()->create(['cav_id' => $this->tree['troopers']['rifleman']->cav_id, 'username' => 'Okafor.B']),
        'outsider' => User::factory()->serving()->create(),
        'view-as' => $users['admin'],
        default => $users[$who],
    };
    if ($who === 'view-as') {
        $this->actingAs($viewer)->post('/view-as', ['kind' => 'unit', 'id' => $this->tree['units']['host']->id])->assertSessionHasNoErrors();
    }

    $pages = driftPages($this->tree);
    $verdicts = $this->actingAs($viewer)->getJson('/__test/bookmark-verdicts?pages='.urlencode(json_encode($pages)))->assertOk()->json();

    $disagree = [];
    foreach ($pages as $index => [$route, $params]) {
        $status = $this->actingAs($viewer)->get(route($route, $params, false))->getStatusCode();
        expect($status)->toBeIn([200, 302, 403, 404], "{$route} ".json_encode($params)." answered {$status}");
        if ($verdicts[$index] !== $status < 400) {
            $disagree[] = "{$route} ".json_encode($params).': bookmark says '.($verdicts[$index] ? 'opens' : 'refused').", page answered {$status}";
        }
    }

    expect($disagree)->toBe([]);
})->with(['admin', 'pl', 'sl', 'grant', 'psg', 'rifleman', 'outsider', 'view-as']);
