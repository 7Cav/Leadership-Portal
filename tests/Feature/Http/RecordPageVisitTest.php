<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordPageVisit;
use App\Models\AuditLogEntry;
use App\Models\PageVisit;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PageVisitRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

// #382: every page a signed-in person opens is logged for the Admin-only activity log: who, the address, when, and any
// act as or view as in effect. Only the address and the time, never a body; recorded after the response.

beforeEach(function () {
    $this->user = User::factory()->serving()->create(['username' => 'Vasquez.R']);

    // Pages of the test's own, so the address rules are checked without depending on any screen's props.
    Route::middleware('web')->group(function () {
        Route::get('/__visit-test/page', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        Route::post('/__visit-test/page', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        Route::get('/__visit-test/json', fn () => response()->json(['ok' => true]));
        Route::get('/auth/__visit-test', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']));
    });
});

function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'];
}

it('records a full page load: the person, the address and the time', function () {
    Carbon::setTestNow('2026-10-28 14:05:09');

    $this->actingAs($this->user)->get('/home')->assertOk();

    $visit = PageVisit::query()->sole();
    expect($visit->user_id)->toBe($this->user->id)
        ->and($visit->url)->toBe('/home')
        ->and($visit->acting_as)->toBeNull()
        ->and($visit->viewing_as)->toBeNull()
        ->and($visit->visited_at->toDateTimeString())->toBe('2026-10-28 14:05:09');
});

it('records an Inertia navigation, which answers JSON', function () {
    $this->actingAs($this->user)->withHeaders(inertiaHeaders())->get('/home')->assertOk()->assertHeader('X-Inertia', 'true');

    expect(PageVisit::query()->sole()->url)->toBe('/home');
});

it('does not record a partial reload or a prefetch of a page', function () {
    $this->actingAs($this->user)->withHeaders([...inertiaHeaders(), 'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'auth'])
        ->get('/home')->assertOk();
    $this->actingAs($this->user)->withHeaders([...inertiaHeaders(), 'Purpose' => 'prefetch'])->get('/home')->assertOk();

    expect(PageVisit::query()->count())->toBe(0);
});

it('does not record a JSON fetch', function () {
    $this->actingAs($this->user)->getJson('/__visit-test/json')->assertOk();
    $this->actingAs($this->user)->getJson('/view-as/options')->assertOk();

    expect(PageVisit::query()->count())->toBe(0);
});

it('does not record anything but a GET, nor a redirect or a refusal', function () {
    $this->actingAs($this->user)->post('/__visit-test/page')->assertOk();
    $this->actingAs($this->user)->get('/')->assertRedirect();
    $this->actingAs($this->user)->get('/settings/audit-log')->assertForbidden();

    expect(PageVisit::query()->count())->toBe(0);
});

it('does not record a guest', function () {
    $this->get('/__visit-test/page')->assertOk();
    $this->get('/login')->assertOk();

    expect(PageVisit::query()->count())->toBe(0);
});

it('keeps the query but drops sensitive parameters on every route', function () {
    $this->actingAs($this->user)->get('/__visit-test/page?unit=4&code=abc&state=xyz&token=t&access_token=a&password=p&key=k&api_key=x&week=2026-10-24')->assertOk();

    expect(PageVisit::query()->sole()->url)->toBe('/__visit-test/page?unit=4&week=2026-10-24');
    expect(RecordPageVisit::address(Request::create('/roster?filter[token]=t&filter[unit]=4')))->toBe('/roster?filter%5Bunit%5D=4');
});

it('drops the short names signed links and one-time codes use, and keeps look-alike names', function () {
    $address = RecordPageVisit::address(Request::create('/roster?sig=s&jwt=j&otp=1&auth=a&pin=2&hmac=h&nonce=n&credentials=c&bearer=b&authorization=z&signed=1&author=x&unit=4'));

    expect($address)->toBe('/roster?signed=1&author=x&unit=4');
});

it('drops the whole query under /auth and /__e2e', function () {
    $this->actingAs($this->user)->get('/auth/__visit-test?unit=4&anything=else')->assertOk();

    expect(PageVisit::query()->sole()->url)->toBe('/auth/__visit-test')
        ->and(RecordPageVisit::address(Request::create('/__e2e/login/Pike.S?to=/home')))->toBe('/__e2e/login/Pike.S')
        ->and(RecordPageVisit::address(Request::create('/auth?x=1')))->toBe('/auth')
        // Only the prefix itself: a path that merely starts with the letters keeps its query.
        ->and(RecordPageVisit::address(Request::create('/authors?x=1')))->toBe('/authors?x=1')
        ->and(RecordPageVisit::address(Request::create('/')))->toBe('/');
});

it('stores the address only, never the request body', function () {
    $this->actingAs($this->user)->call('GET', '/__visit-test/page', [], [], [], [], 'secret body')->assertOk();

    $visit = PageVisit::query()->sole();
    expect($visit->url)->toBe('/__visit-test/page')
        ->and(json_encode($visit->getAttributes()))->not->toContain('secret body');
});

it('cuts a very long address', function () {
    $this->actingAs($this->user)->get('/__visit-test/page?q='.str_repeat('a', 3000))->assertOk();

    expect(mb_strlen(PageVisit::query()->sole()->url))->toBe(PageVisit::MAX_URL_LENGTH);
});

it('writes no audit entry: a visit is not a state change (AD-16)', function () {
    $this->actingAs($this->user)->get('/home')->assertOk();

    expect(PageVisit::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

describe('act as and view as', function () {
    beforeEach(function () {
        $repo = app(UnitRepository::class);
        $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
        $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);
        UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5900]);
        $trooper = Trooper::factory()->create(['cav_id' => 901, 'cav_position_id' => 5900, 'status' => TrooperStatus::Combat, 'rank' => 'SGT']);
        $this->user->forceFill(['cav_id' => 901, 'forum_user_id' => $trooper->forum_user_id])->save();
        Trooper::factory()->create(['cav_id' => 950, 'status' => TrooperStatus::Combat]);
        $this->admin = User::factory()->admin()->create(['username' => 'Hale.S', 'cav_id' => 950]);
    });

    it('records the real Admin and who they acted as', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->user->id])->assertRedirect('/home');
        $this->actingAs($this->admin)->get('/home')->assertOk();

        $visit = PageVisit::query()->sole();
        expect($visit->user_id)->toBe($this->admin->id)
            ->and($visit->acting_as)->toBe('SGT Vasquez.R')
            ->and($visit->viewing_as)->toBeNull();
    });

    it('records the billet an Admin acted as', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->section->id])->assertRedirect('/home');
        $this->actingAs($this->admin)->get('/home')->assertOk();

        $visit = PageVisit::query()->sole();
        expect($visit->user_id)->toBe($this->admin->id)
            ->and($visit->acting_as)->toBe($this->section->billet());
    });

    it('records who a view as previews', function () {
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'user', 'id' => $this->user->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/home')->assertOk();

        $visit = PageVisit::query()->sole();
        expect($visit->user_id)->toBe($this->admin->id)
            ->and($visit->acting_as)->toBeNull()
            ->and($visit->viewing_as)->toBe('SGT Vasquez.R');
    });
});

it('records after the response: nothing is written until the request terminates', function () {
    $middleware = app(RecordPageVisit::class);
    $request = Request::create('/home');
    $request->setUserResolver(fn () => $this->user);
    $response = response('<html></html>', 200, ['Content-Type' => 'text/html']);

    $middleware->handle($request, fn () => $response);
    expect(PageVisit::query()->count())->toBe(0);

    $middleware->terminate($request, $response);
    expect(PageVisit::query()->sole()->url)->toBe('/home');
});

it('never fails the page when the log cannot be written, and reports why', function () {
    Exceptions::fake();
    $this->app->bind(PageVisitRepository::class, fn () => new class implements PageVisitRepository
    {
        public function record(int $userId, string $url, ?string $actingAs, ?string $viewingAs, DateTimeInterface $at): void
        {
            throw new RuntimeException('page_visits is unavailable');
        }

        public function filtered(?int $userId = null, ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 50): LengthAwarePaginator
        {
            throw new LogicException('unused');
        }

        public function users(): Collection
        {
            throw new LogicException('unused');
        }

        public function pruneBefore(DateTimeInterface $cutoff, int $chunk = 1000): int
        {
            throw new LogicException('unused');
        }
    });

    $this->actingAs($this->user)->get('/home')->assertOk();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'page_visits is unavailable');
});
