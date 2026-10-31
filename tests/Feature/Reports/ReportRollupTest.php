<?php

use App\Kpi\KpiDelta;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

// Story 5.8: the report roll-up workspace. Scope from the ScopeResolver (AD-8), 403 outside it,
// a leaf leader straight to their own report, and one batched read for the whole chain.
// "Now" is Thu 08OCT26, so the current week is the one opening Sat 03OCT26, due Sat 10OCT26 23:59Z.
// Fixture tree:
//   1-7 ─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A   (sent, by Haddad.Y)
//                │       └─ 2/1/A   (draft)
//                └─ 2/A ─── 1/2/A   (not started)
//       └ B/1-7 (sibling company)

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-08 16:20:30');
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->p1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->p2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->s11 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->p1->id);
    $this->s21 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->p1->id);
    $this->s12 = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->p2->id);
    // Someone in every section and in Bravo's HQ, so no unit is an empty one the chain leaves out (#442).
    manUnits($this->s11, $this->s21, $this->s12, $this->bravo);

    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->sender = User::factory()->create(['username' => 'Haddad.Y']);
    $this->reports = app(WeeklyReportRepository::class);
    $this->snapshot = new ReportSnapshot(new UnitKpis(6, new Ratio(20, 24), new Ratio(10, 12), 1, 0, 0), new KpiDelta(0, 200, -100, 1, 0, 0));
    $this->reports->send($this->s11->id, $this->week, $this->snapshot->toArray(), 'Full house.', $this->sender->id, 'digest', null);
    $this->reports->saveDraft($this->s21->id, $this->week, [], 'Still waiting on two.');

    $this->commander = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->commander->id, 'unit_id' => $this->company->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('renders the viewer\'s scope as a tree for the current week', function () {
    $this->actingAs($this->commander)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Rollup')
            ->where('week.start', '2026-10-03T00:00:00+00:00')
            ->where('week.reportDue', '2026-10-10T23:59:00+00:00')
            ->where('week.isCurrent', true)
            ->where('week.options.0', '2026-10-03')
            ->where('week.options.1', '2026-09-26')
            ->where('scopeUnitId', null)
            ->has('roots', 1)
            ->where('roots.0.id', $this->company->id)
            ->where('roots.0.name', 'Alpha Company')
            ->where('roots.0.callsign', 'A/1-7')
            ->where('roots.0.type', 'company')
            ->where('roots.0.status', 'not_started')
            ->where('roots.0.holding', true)
            ->where('roots.0.open', true)
            ->where('roots.0.coverage', ['sent' => 0, 'of' => 2, 'missing' => [$this->p1->id, $this->p2->id]])
            ->where('roots.0.reportUrl', "/reports/{$this->company->id}/2026-10-03")
            ->where('roots.0.children.0.id', $this->p1->id)
            ->where('roots.0.children.0.coverage', ['sent' => 1, 'of' => 2, 'missing' => [$this->s21->id]])
            ->where('roots.0.children.0.kpis', ['strength' => 6, 'rc' => ['numerator' => 20, 'denominator' => 24], 'sp' => ['numerator' => 10, 'denominator' => 12], 'atRisk' => 1, 'onLoa' => 0, 'makeups' => 0])
            ->where('roots.0.children.0.children.0.status', 'sent')
            ->where('roots.0.children.0.children.0.live', false)
            ->where('roots.0.children.0.children.0.notes', 'Full house.')
            ->where('roots.0.children.0.children.0.sentBy', 'Haddad.Y')
            ->where('roots.0.children.0.children.0.delta', ['strength' => 0, 'rcBp' => 200, 'spBp' => -100, 'atRisk' => 1, 'onLoa' => 0, 'makeups' => 0])
            ->where('roots.0.children.0.children.0.coverage', null)
            ->where('roots.0.children.0.children.1.status', 'draft')
            ->where('roots.0.children.0.children.1.live', true)
            ->where('roots.0.children.0.children.1.notes', 'Still waiting on two.')
            ->where('roots.0.children.0.children.1.sentAt', null)
            ->where('roots.0.children.1.children.0.status', 'not_started')
            ->where('roots.0.children.1.children.0.children', [])
        );
});

it('gives an admin the whole tree from its top', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('roots', 1)
            ->where('roots.0.id', $this->battalion->id)
            ->has('roots.0.children', 2)
        );
});

it('opens the chain under a unit in scope', function () {
    $this->actingAs($this->commander)->get("/reports/{$this->p1->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scopeUnitId', $this->p1->id)
            ->has('roots', 1)
            ->where('roots.0.id', $this->p1->id)
        );
});

it('refuses a unit outside the viewer\'s scope', function (string $unit) {
    $this->actingAs($this->commander)->get('/reports/'.$this->{$unit}->id)->assertForbidden();
})->with(['sibling company' => 'bravo', 'parent battalion' => 'battalion']);

it('404s a unit that does not exist', function () {
    $this->actingAs($this->commander)->get('/reports/999999')->assertNotFound();
});

it('sends a section leader straight to their own section\'s report for the current week', function () {
    $leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->s21->id]);

    $this->actingAs($leader)->get('/reports')->assertRedirect("/reports/{$this->s21->id}/2026-10-03");
});

it('sends a leaf unit\'s workspace to its report, keeping the requested week', function () {
    $this->actingAs($this->commander)->get("/reports/{$this->s12->id}?week=2026-09-26")
        ->assertRedirect("/reports/{$this->s12->id}/2026-09-26");
});

it('shows an empty workspace to a user with no scope', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Rollup')->where('roots', []));
});

it('shows a past week read from that week\'s reports', function () {
    $this->actingAs($this->commander)->get('/reports?week=2026-09-26')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('week.start', '2026-09-26T00:00:00+00:00')
            ->where('week.isCurrent', false)
            ->where('roots.0.children.0.children.0.status', 'not_started')
        );
});

it('keeps the closing week current until its report deadline passes', function () {
    CarbonImmutable::setTestNow('2026-10-10 23:58:59');
    $this->actingAs($this->commander)->get('/reports')->assertInertia(fn (AssertableInertia $page) => $page->where('week.start', '2026-10-03T00:00:00+00:00'));

    CarbonImmutable::setTestNow('2026-10-10 23:59:00');
    $this->actingAs($this->commander)->get('/reports')->assertInertia(fn (AssertableInertia $page) => $page->where('week.start', '2026-10-10T00:00:00+00:00'));
});

it('404s a week that is not a week boundary, malformed, or in the future', function (string $week) {
    $this->actingAs($this->commander)->get("/reports?week={$week}")->assertNotFound();
})->with([
    'a Thursday' => '2026-10-01',
    'malformed' => 'last-week',
    'not a date' => '2026-13-45',
    'an overflowing day that lands on this week' => '2026-09-33',
    'future' => '2026-10-10',
    'older than the selector offers' => '2026-07-04',
]);

it('404s a week given as an array', function () {
    $this->actingAs($this->commander)->get('/reports?week[]=2026-10-03')->assertNotFound();
});

it('accepts the oldest week the selector offers', function () {
    $this->actingAs($this->commander)->get('/reports?week=2026-07-11')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('week.options.12', '2026-07-11'));
});

it('shows one tree per separate unit in scope, without redirecting a leader of two sections', function () {
    $leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->s21->id]);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->s11->id]);

    $this->actingAs($leader)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('roots', 2)
            ->where('roots.0.id', $this->s11->id)
            ->where('roots.1.id', $this->s21->id)
        );
});

it('folds a unit nested inside another granted unit into the outer one', function () {
    PortalRoleGrant::factory()->create(['user_id' => $this->commander->id, 'unit_id' => $this->s21->id]);

    $this->actingAs($this->commander)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('roots', 1)->where('roots.0.id', $this->company->id));
});

it('reads a whole battalion in a constant number of queries', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $units = app(UnitRepository::class);
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/reports')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // One trooper is placed first, so the baseline already includes the LOA coverage read (4.8), which only
    // runs when someone is assigned.
    Trooper::factory()->create()->forceFill(['primary_section_id' => $this->s11->id])->save();

    $small = $count();

    foreach (['C', 'D'] as $letter) {
        $company = $units->create(UnitType::Company, "{$letter} Company", "{$letter}/1-7", $this->battalion->id);
        foreach ([1, 2] as $p) {
            $platoon = $units->create(UnitType::Platoon, "Platoon {$p}", "{$p}/{$letter}/1-7", $company->id);
            foreach ([1, 2, 3, 4] as $s) {
                $section = $units->create(UnitType::Section, "Section {$s}", "{$s}/{$p}/{$letter}/1-7", $platoon->id);
                Trooper::factory()->create()->forceFill(['primary_section_id' => $section->id])->save();
                $sender = User::factory()->create();
                $this->reports->send($section->id, $this->week, $this->snapshot->toArray(), null, $sender->id, 'digest', null);
            }
        }
    }

    expect($count())->toBe($small);
});

it('sends a guest to login', function () {
    $this->get('/reports')->assertRedirect(route('login'));
});

it('never registers the browser-test sign-in outside the e2e environment', function () {
    $this->get('/__e2e/login/Haddad.Y')->assertNotFound();
    expect(auth()->check())->toBeFalse();
});

// AD-9: view-as narrows the workspace to the target's scope, never widens it.
it('narrows the workspace to a unit viewed as', function () {
    $this->actingAs($this->commander)->post('/view-as', ['kind' => 'unit', 'id' => $this->p1->id]);

    $this->actingAs($this->commander)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('roots', 1)->where('roots.0.id', $this->p1->id));
    $this->actingAs($this->commander)->get("/reports/{$this->company->id}")->assertForbidden();
});

it('renders the chain when one sent snapshot is unreadable, showing that unit as unavailable', function () {
    WeeklyReport::query()->where('unit_id', $this->s11->id)->update(['kpis' => json_encode(['corrupt' => true])]);

    $this->actingAs($this->commander)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roots.0.children.0.children.0.id', $this->s11->id)
            ->where('roots.0.children.0.children.0.status', 'sent')
            ->where('roots.0.children.0.children.0.kpis', null)
            ->where('roots.0.children.0.children.0.delta', null)
            // Its unsent platoon has nothing true to sum.
            ->where('roots.0.children.0.kpis', null)
            ->where('roots.0.children.0.coverage', null)
            // Everything else still reads.
            ->whereNot('roots.0.children.0.children.1.kpis', null)
            ->whereNot('roots.0.children.1.kpis', null));
});
