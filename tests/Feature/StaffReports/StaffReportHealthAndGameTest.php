<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Kpi\StaffHqHealth;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The staff report form offers only the sections that play the HQ's game, and a reader sees the HQ unit's health for
// the week beside the staff reports, in a bounded number of queries.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->psg = $this->tree['troopers']['psg'];
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03', 'UTC');
    $this->url = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);
    // Someone in the second section too, so both stay in the picker.
    Trooper::factory()->create(['primary_section_id' => $this->tree['units']['other']->id]);
});

function sectionIdsOffered($test): array
{
    $ids = [];
    $test->actingAs($test->tree['users']['psg'])->get($test->url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$ids) {
        $ids = array_column($page->toArray()['props']['sections'], 'id');
    });

    return $ids;
}

it('offers an HLL HQ only HLL sections, and keeps a section with no game', function () {
    $units = app(UnitRepository::class);
    $this->tree['units']['company']->update(['game_id' => game('hll')->id]);
    // A platoon under the same company that plays another game: its section is left out.
    $armaPlatoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->tree['units']['company']->id);
    $armaPlatoon->update(['game_id' => game('arma')->id]);
    $arma = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $armaPlatoon->id);
    Trooper::factory()->create(['primary_section_id' => $arma->id]);
    // A section in another battalion with no game on its chain: kept.
    $battalion2 = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->tree['units']['regiment']->id);
    $company2 = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $battalion2->id);
    $platoon2 = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $company2->id);
    $loose = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $loose->id]);

    $offered = sectionIdsOffered($this);

    expect($offered)->toContain($this->tree['units']['host']->id, $this->tree['units']['other']->id, $loose->id)
        ->and($offered)->not->toContain($arma->id);
});

it('filters nothing when the HQ has no game', function () {
    $units = app(UnitRepository::class);
    $platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->tree['units']['company']->id);
    $platoon2->update(['game_id' => game('arma')->id]);
    $arma = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $arma->id]);

    expect(sectionIdsOffered($this))->toContain($arma->id, $this->tree['units']['host']->id);
});

it('shows a reader the HQ unit health for the week with an 8 week trend, and how many staff kept the rule', function () {
    app(SendStaffReportHandler::class)->handle($this->tree['users']['psg'], new SendStaffReport($this->psg->id, $this->week, true, [$this->tree['units']['host']->id]));

    $this->actingAs($this->tree['users']['co'])->get($this->url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StaffReport')
        ->where('health.unit', ['id' => $this->tree['units']['platoon']->id, 'label' => '1/A/1-7'])
        ->where('health.week', '2026-10-03')
        ->has('health.trend', StaffHqHealth::TREND_WEEKS)
        ->where('health.trend.7.week', '2026-10-03')
        ->has('health.sp')
        ->has('health.rc')
        ->has('health.headcount')
        ->has('health.inRollcall')
        ->where('health.atRisk', 0)
        ->where('health.staff', ['total' => 1, 'met' => 1]));
});

it('never gives the staff member the health panel on their own page', function () {
    $this->actingAs($this->tree['users']['psg'])->get($this->url)->assertInertia(fn (AssertableInertia $page) => $page->where('health', null));
});

it('gives the overview the health of the first listed HQ, or the one picked among those listed', function () {
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StaffReports')
        ->where('health.unit.id', $this->tree['units']['platoon']->id)
        ->where('health.hqs.0.id', $this->tree['units']['platoon']->id)
        ->where('health.staff.total', 1)
        ->has('health.trend', StaffHqHealth::TREND_WEEKS));

    // An HQ the viewer does not read falls back to the first listed.
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03&hq='.$this->tree['units']['s6']->id)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('health.unit.id', $this->tree['units']['platoon']->id));
});

it('reads the health in the same number of queries however big the unit is', function () {
    $health = app(StaffHqHealth::class);
    $platoon = $this->tree['units']['platoon'];
    $count = function () use ($health, $platoon) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $health->forUnit($platoon, $this->week);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $small = $count();

    $units = app(UnitRepository::class);
    foreach (range(3, 6) as $n) {
        $section = $units->create(UnitType::Section, "Section {$n}", "{$n}/1/A/1-7", $platoon->id);
        Trooper::factory()->count(5)->create(['primary_section_id' => $section->id]);
    }

    expect($count())->toBe($small)
        ->and($small)->toBeLessThanOrEqual(StaffHqHealth::TREND_WEEKS * 12 + 3);
});
