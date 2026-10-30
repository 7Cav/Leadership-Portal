<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The staff reports overview: who opens it, whose rows each reader gets, the week it opens on and the statuses.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->psg = $this->tree['troopers']['psg'];
    // Saturday after the fixture's week: 03Oct26 has ended and is the last ended week.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);
    // A second company in the battalion, with a platoon HQ staff member of its own.
    $this->companyB = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->tree['units']['battalion']->id);
    $this->platoonB = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->companyB->id);
    $this->psgB = Trooper::factory()->create(['primary_section_id' => $this->platoonB->id, 'username' => 'Quinn.R']);
    $this->coB = User::factory()->commanding($this->companyB, ['primary_section_id' => $this->companyB->id])->create();
    $this->battalionCo = User::factory()->commanding($this->tree['units']['battalion'], ['primary_section_id' => $this->tree['units']['battalion']->id])->create();
});

function overviewTrooperOf(User $user): Trooper
{
    return Trooper::query()->where('cav_id', $user->cav_id)->sole();
}

/** The trooper ids per HQ label on the overview, for `$user`. */
function overviewRows(User $user, string $query = ''): array
{
    $groups = test()->actingAs($user)->get('/staff-reports'.$query)->assertOk()->viewData('page')['props']['groups'];

    return collect($groups)->mapWithKeys(fn (array $group) => [$group['label'] => collect($group['rows'])->pluck('trooperId')->sort()->values()->all()])->all();
}

it('shows a company commander the platoon HQ staff under them, and not a peer company\'s', function () {
    $pl = overviewTrooperOf($this->tree['users']['pl']);

    expect(overviewRows($this->tree['users']['co']))->toBe(['1/A/1-7' => collect([$this->psg->id, $pl->id])->sort()->values()->all()]);
    expect(overviewRows($this->coB))->toBe(['1/B/1-7' => [$this->psgB->id]]);
});

it('shows battalion command the company and platoon HQs down the tree', function () {
    $groups = $this->actingAs($this->battalionCo)->get('/staff-reports')->assertOk()->viewData('page')['props']['groups'];

    expect(collect($groups)->pluck('label')->all())->toBe(['A/1-7', '1/A/1-7', 'B/1-7', '1/B/1-7']);
    expect(collect($groups)->firstWhere('label', 'A/1-7')['rows'][0]['trooperId'])->toBe(overviewTrooperOf($this->tree['users']['co'])->id);
});

it('shows an Admin every HQ', function () {
    expect(array_keys(overviewRows($this->tree['users']['admin'])))->toBe(['A/1-7', '1/A/1-7', 'B/1-7', '1/B/1-7']);
});

it('answers 404 to a PL, a PSG and a section leader, who read only their own', function (string $viewer) {
    $this->actingAs($this->tree['users'][$viewer])->get('/staff-reports')->assertNotFound();
})->with(['pl', 'psg', 'sl']);

it('opens on the last ended week, with sent, not sent and the 1-practice rule', function () {
    app(SendStaffReportHandler::class)->handle($this->tree['users']['psg'], new SendStaffReport($this->psg->id, CarbonImmutable::parse('2026-10-03', 'UTC'), true, [$this->tree['units']['host']->id]));

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StaffReports')
        ->where('week.date', '2026-10-03')
        ->where('week.ended', true)
        ->where('week.next', '2026-10-10')
        // The CO is staff on the company HQ themselves: their own report sits on top, not in the rows.
        ->where('own.status', 'notSent')
        ->where('groups.0.rows', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id) === [
            'trooperId' => $this->psg->id,
            'name' => $this->psg->display_name,
            'billet' => $this->psg->billet,
            'hq' => '1/A/1-7',
            'status' => 'sent',
            'attended' => true,
            'practices' => ['1/1/A/1-7'],
            'metRule' => true,
            'href' => route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']),
        ] && collect($rows)->firstWhere('trooperId', overviewTrooperOf($this->tree['users']['pl'])->id)['status'] === 'notSent'));
});

it('reads the running week as not due, and 404s a week that is not a Cav week or has not started', function () {
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-10')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('week.ended', false)
        ->where('week.next', null)
        ->where('groups.0.rows.0.status', 'notDue')
        ->where('groups.0.rows.0.metRule', null));

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-05')->assertNotFound();
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-17')->assertNotFound();
});

it('puts the reader\'s own report on top with a link to send it, and not again in their HQ\'s rows', function () {
    $co = overviewTrooperOf($this->tree['users']['co']);

    $this->actingAs($this->battalionCo)->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page->where('own', null));
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('own.trooperId', $co->id)
        ->where('own.status', 'notSent')
        ->where('own.href', route('staff-report.show', ['trooper' => $co->id, 'week' => '2026-10-03'])));
    expect(collect(overviewRows($this->battalionCo)['A/1-7']))->toContain($co->id);
});

it('reads the overview in the same number of queries for one HQ and many', function () {
    $count = function (User $user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->get('/staff-reports')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $units = app(UnitRepository::class);
    $count($this->battalionCo);
    $few = $count($this->battalionCo);

    foreach (range(2, 5) as $n) {
        $platoon = $units->create(UnitType::Platoon, "Platoon {$n}", "{$n}/B/1-7", $this->companyB->id);
        Trooper::factory()->count(2)->create(['primary_section_id' => $platoon->id]);
    }

    expect($count($this->battalionCo))->toBe($few);
});
