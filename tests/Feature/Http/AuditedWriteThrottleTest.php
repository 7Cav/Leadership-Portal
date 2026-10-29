<?php

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Every audited write is rate limited (AD-16, #453): each request writes an audit row, so a client repeating one could
// grow the log without bound. Most share one per-user bucket (audited-writes); the rest have a bucket of their own
// sized for how the screen sends them. The first test walks the route table, so a new write route without a throttle
// fails here rather than in a review.

// The write routes deliberately left without a throttle, each with why. Keep this short: a route earns a place here
// only when a limit would do harm and something else already bounds it.
const UNTHROTTLED_WRITES = [
    // Signing out may record a view as or act as ending, but it ends the session: repeating it means signing in again,
    // which the login limiter caps per IP. A limit here could only keep someone from signing out.
    'logout',
];

/**
 * Every route that takes a write from a signed-in user: behind `auth`, with any method besides GET and HEAD. Every
 * audited write needs an actor, so all of them are here; the framework's own routes outside the group (the signed
 * local-disk upload) are not the app's writes.
 */
function writeRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route) => array_diff($route->methods(), ['GET', 'HEAD']) === [])
        // `Route::permanentRedirect()` registers for every method, but only ever answers with a redirect.
        ->reject(fn (RoutingRoute $route) => str_contains($route->getActionName(), RedirectController::class))
        ->values();
}

/** The named limiters a route is throttled by (`throttle:<name>`). */
function limitersOf(RoutingRoute $route): array
{
    return collect($route->gatherMiddleware())
        ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'))
        ->map(fn (string $middleware) => explode(',', substr($middleware, strlen('throttle:')))[0])
        ->values()
        ->all();
}

it('throttles every write route with a registered limiter, but the listed exceptions', function () {
    $routes = writeRoutes();

    // A guard on the walk itself: a filter that matched nothing would pass every assertion below.
    expect($routes->count())->toBeGreaterThan(50);

    $missing = $routes
        ->reject(fn (RoutingRoute $route) => in_array($route->getName(), UNTHROTTLED_WRITES, true))
        ->filter(fn (RoutingRoute $route) => limitersOf($route) === [])
        ->map(fn (RoutingRoute $route) => ($route->getName() ?? '(unnamed)').' '.implode('|', $route->methods()).' /'.$route->uri());
    expect($missing->all())->toBe([], 'These write routes have no throttle: add one, or list the route in UNTHROTTLED_WRITES with why.');

    // A misspelt limiter name would not reach the limiter meant, so check each named one is registered.
    $unknown = $routes->flatMap(fn (RoutingRoute $route) => limitersOf($route))
        ->unique()
        ->filter(fn (string $name) => RateLimiter::limiter($name) === null && ! is_numeric($name));
    expect($unknown->values()->all())->toBe([]);

    // The exceptions are real routes, and really unthrottled, so a stale entry does not hide a new route of that name.
    foreach (UNTHROTTLED_WRITES as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()->and(limitersOf($route))->toBe([]);
    }
});

it('puts the writes the 2026-10-28 review found unlimited on the right buckets', function () {
    $expected = [
        'rollcall.store' => 'rollcall-marks',
        'rollcall.unmatched.link' => 'audited-writes',
        'rollcall.confirm' => 'audited-writes',
        'rollcall.posted' => 'audited-writes',
        'reports.draft' => 'report-drafts',
        'reports.send' => 'report-sends',
        'game-identities.update' => 'audited-writes',
        'game-identities.destroy' => 'audited-writes',
        // The Admin settings writes are audited too.
        'settings.org-structure.store' => 'audited-writes',
        'settings.trooper-placement.store' => 'audited-writes',
        'settings.staff-grants.store' => 'audited-writes',
        'settings.games.store' => 'audited-writes',
        'settings.servers.update' => 'audited-writes',
        'settings.demo-data.store' => 'audited-writes',
        // The import reads Cav on the request, so it shares the roster sync's tight bucket.
        'settings.org-structure.import' => 'roster-sync',
        'counseling.store' => 'counseling-uploads',
        'counseling.overturn' => 'audited-writes',
    ];

    foreach ($expected as $name => $limiter) {
        expect(limitersOf(Route::getRoutes()->getByName($name)))->toBe([$limiter], $name);
    }
});

it('answers 429 after 60 of the shared writes in a minute from one user, across all of them, without slowing anyone else', function () {
    $units = app(UnitRepository::class);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', null);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $week = '2026-10-03';
    $requests = [
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams", []),
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams/options", []),
        fn () => $this->postJson("/rollcall/{$section->id}/fireteams/policy", []),
        fn () => $this->postJson("/rollcall/{$section->id}/unmatched/dismiss", []),
        fn () => $this->postJson("/rollcall/{$section->id}/unmatched/restore", []),
        fn () => $this->postJson("/rollcall/{$section->id}/unmatched/link", []),
        fn () => $this->postJson("/rollcall/{$section->id}/confirm", []),
        fn () => $this->postJson("/rollcall/{$section->id}/posted", []),
        fn () => $this->putJson("/reports/{$platoon->id}/{$week}/topic", []),
        fn () => $this->deleteJson("/reports/{$platoon->id}/{$week}/topic"),
        fn () => $this->putJson('/game-identities/999999/squad', []),
        fn () => $this->deleteJson('/game-identities/999999/squad'),
        fn () => $this->putJson("/units/{$platoon->id}/guidon", []),
    ];
    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();

    $this->actingAs($user);
    foreach (range(0, 59) as $i) {
        expect($requests[$i % count($requests)]()->status())->not->toBe(429);
    }

    foreach ($requests as $request) {
        $request()->assertTooManyRequests();
    }

    $this->actingAs($other);
    expect($requests[0]()->status())->not->toBe(429);
});

it('never slows a leader marking a whole section in a few seconds, and caps the autosave at 240 a minute', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '910/']);
    $section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '910/911/']);
    $leader = User::factory()->commanding($section)->create();
    $troopers = Trooper::factory()->count(15)->create(['primary_section_id' => $section->id]);
    $save = fn (array $mark) => $this->actingAs($leader)->postJson("/rollcall/{$section->id}", ['week' => '2026-10-03', 'marks' => [$mark]]);

    // Worse than the screen ever sends: every change its own request, with no debounce to batch them. Each trooper is
    // marked present, signed, then changed to missed: 45 saves, all accepted and audited.
    foreach ($troopers as $trooper) {
        $save(['trooper_id' => $trooper->id, 'status' => 'present'])->assertOk();
        $save(['trooper_id' => $trooper->id, 'rollcall' => 'will_attend'])->assertOk();
        $save(['trooper_id' => $trooper->id, 'status' => 'missed'])->assertOk();
    }
    expect(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(45);

    // The marks have a bucket of their own: the shared one is untouched.
    expect($this->postJson("/rollcall/{$section->id}/fireteams", [])->status())->not->toBe(429);

    // The rest of the minute's 240, then the screen gets a 429 it can show, and keeps the leader's changes.
    foreach (range(46, 240) as $i) {
        expect($save(['trooper_id' => $troopers[0]->id, 'status' => 'present'])->status())->toBe(200);
    }
    $save(['trooper_id' => $troopers[0]->id, 'status' => 'present'])
        ->assertTooManyRequests()
        ->assertJson(['message' => 'Too many saves in a minute. Your changes are kept; retry in a moment.']);
    expect(Attendance::query()->where('trooper_id', $troopers[0]->id)->sole()->status)->toBe(AttendanceStatus::Present);
});

it('gives report drafts and sends their own buckets: 120 drafts and 10 sends a minute per user', function () {
    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();
    $this->actingAs($user);

    // No such unit: each request is refused, and still spends a slot.
    foreach (range(1, 10) as $i) {
        expect($this->postJson('/reports/999999/2026-10-03/send', [])->status())->not->toBe(429);
    }
    $this->postJson('/reports/999999/2026-10-03/send', [])->assertTooManyRequests();

    foreach (range(1, 120) as $i) {
        expect($this->putJson('/reports/999999/2026-10-03', [])->status())->not->toBe(429);
    }
    $this->putJson('/reports/999999/2026-10-03', [])->assertTooManyRequests();

    // Neither spends the shared bucket.
    expect($this->deleteJson('/reports/999999/2026-10-03/topic')->status())->not->toBe(429);

    $this->actingAs($other);
    expect($this->postJson('/reports/999999/2026-10-03/send', [])->status())->not->toBe(429);
});

it('gives filing a DCS or LOR its own, tighter bucket: 10 uploads a minute per user', function () {
    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();

    // No such trooper: each request is refused with a 404, and still spends a slot.
    $this->actingAs($user);
    foreach (range(1, 10) as $i) {
        expect($this->post('/troopers/999999/counseling', [])->status())->toBe(404);
    }
    $this->post('/troopers/999999/counseling', [])->assertTooManyRequests();

    // A separate bucket: the shared audited writes are untouched by it.
    expect($this->deleteJson('/reports/999999/2026-10-03/topic')->status())->not->toBe(429);

    $this->actingAs($other);
    expect($this->post('/troopers/999999/counseling', [])->status())->toBe(404);
});

it('caps loading the act as list, a heavy read, at 20 a minute per user', function () {
    expect(limitersOf(Route::getRoutes()->getByName('act-as.options')))->toBe(['act-as-options']);

    $user = User::factory()->serving()->create();
    $other = User::factory()->serving()->create();

    // Someone with nobody to act as is refused, and still spends a slot: the limit runs before the controller.
    $this->actingAs($user);
    foreach (range(1, 20) as $i) {
        expect($this->getJson('/act-as/options')->status())->not->toBe(429);
    }
    $this->getJson('/act-as/options')->assertTooManyRequests();

    // Its own bucket: the shared audited writes are untouched by it.
    expect($this->deleteJson('/reports/999999/2026-10-03/topic')->status())->not->toBe(429);

    $this->actingAs($other);
    expect($this->getJson('/act-as/options')->status())->not->toBe(429);
});
