<?php

use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\PracticeCapturePlayer;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The staff report page, who reads it, how the staff member reaches it (the sidebar and Home), and
// how a platoon's or company's report shows whether its HQ staff sent theirs.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->psg = $this->tree['troopers']['psg'];
    $this->host = $this->tree['units']['host'];
    $this->url = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);
});

function sendOwnStaffReport(User $user, Trooper $trooper, bool $attended, array $sectionIds): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($user, new SendStaffReport($trooper->id, CarbonImmutable::parse('2026-10-03', 'UTC'), $attended, $sectionIds));
}

function pageTrooperOf(User $user): Trooper
{
    return Trooper::query()->where('cav_id', $user->cav_id)->sole();
}

it('opens the viewer\'s own report for the week whose report is due', function () {
    $this->actingAs($this->tree['users']['psg'])->get('/staff-report')->assertRedirect($this->url);
});

it('offers the staff member the picker, their own platoon\'s sections first, with the week\'s entries ticked and the game server\'s hint', function () {
    app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['pl'], new RecordStaffPractice($this->psg->id, $this->host->id, CarbonImmutable::parse('2026-10-03', 'UTC')));
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->tree['units']['other']->id, CarbonImmutable::parse('2026-10-03', 'UTC'));
    PracticeCapturePlayer::query()->create(['section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => 'Brandt', 'platform_id' => '76561190000000020', 'trooper_id' => $this->psg->id]);
    // A section in another platoon, with someone in it.
    $platoon2 = app(UnitRepository::class)->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->tree['units']['company']->id);
    $far = app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/2/A/1-7', $platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $far->id]);
    Trooper::factory()->create(['primary_section_id' => $this->tree['units']['other']->id]);

    $this->actingAs($this->tree['users']['psg'])->get($this->url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StaffReport')
        ->where('self', true)
        ->where('canSend', true)
        ->where('report', null)
        ->where('hq.label', '1/A/1-7')
        ->where('week.due', '2026-10-10T23:59:00+00:00')
        ->where('entries.0.sectionId', $this->host->id)
        ->where('entries.0.enteredBy', 'Mercer.J')
        ->where('entries.0.selfReported', false)
        ->where('seen', [$this->tree['units']['other']->id])
        ->where('sections', [
            ['id' => $this->host->id, 'label' => '1/1/A/1-7', 'own' => true],
            ['id' => $this->tree['units']['other']->id, 'label' => '2/1/A/1-7', 'own' => true],
            ['id' => $far->id, 'label' => '1/2/A/1-7', 'own' => false],
        ]));
});

it('sends from the page and reads back as sent, picker gone', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post($this->url, ['attended' => true, 'section_ids' => [$this->host->id]])
        ->assertRedirect($this->url);

    $this->actingAs($this->tree['users']['psg'])->get($this->url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('canSend', false)
        ->where('report.attended', true)
        ->where('report.practices', [['sectionId' => $this->host->id, 'section' => '1/1/A/1-7', 'own' => true]])
        ->where('sections', []));
});

it('turns a refusal into a message on its field', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post($this->url, ['attended' => true, 'section_ids' => []])
        ->assertSessionHasErrors(['section_ids' => 'Pick at least one section practice you attended, or answer No.']);

    $this->actingAs($this->tree['users']['psg'])
        ->post($this->url, ['section_ids' => []])
        ->assertSessionHasErrors('attended');

    expect(StaffReport::query()->count())->toBe(0);
});

it('lets the staff member, the chain above their HQ, a read grant there and Admins read it, and nobody else', function (string $viewer, bool $reads) {
    sendOwnStaffReport($this->tree['users']['psg'], $this->psg, false, []);

    $response = $this->actingAs($this->tree['users'][$viewer])->get($this->url);
    $reads ? $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('report.attended', false)->where('canSend', false))
        : $response->assertNotFound();
})->with([
    'the staff member' => ['psg', true],
    'the company commander above the platoon HQ' => ['co', true],
    'a staff grant on the company' => ['grant', true],
    'an Admin' => ['admin', true],
    'the platoon leader beside them on the HQ' => ['pl', false],
    'a section leader below' => ['sl', false],
    'a staff department member' => ['s6Member', false],
]);

it('tells a trooper with no report that week there is nothing to send, and shows nobody else a page for them', function () {
    $sl = $this->tree['users']['sl'];
    $own = route('staff-report.show', ['trooper' => pageTrooperOf($sl)->id, 'week' => '2026-10-03']);

    $this->actingAs($sl)->get($own)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('hq', null)->where('canSend', false));
    $this->actingAs($this->tree['users']['admin'])->get($own)->assertNotFound();
});

it('answers 404 for a week that is not a Cav week or has not started', function (string $week) {
    $this->actingAs($this->tree['users']['psg'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => $week]))->assertNotFound();
})->with(['a Monday' => ['2026-10-05'], 'next week' => ['2026-10-10']]);

it('shows "Staff report" in the sidebar for platoon and company HQ staff only', function (string $viewer, bool $shown) {
    $this->actingAs($this->tree['users'][$viewer])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('features.staffReport', $shown));
})->with([
    'a PSG' => ['psg', true],
    'a PL' => ['pl', true],
    'a company commander' => ['co', true],
    'a section leader' => ['sl', false],
    'a staff department member' => ['s6Member', false],
    'an Admin with no milpac' => ['admin', false],
]);

it('puts the unsent report on Home until it is sent', function () {
    $this->actingAs($this->tree['users']['psg'])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('staffReport.week', '2026-10-03')
        ->where('staffReport.href', $this->url));

    sendOwnStaffReport($this->tree['users']['psg'], $this->psg, false, []);

    $this->actingAs($this->tree['users']['psg'])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('staffReport', null));
    $this->actingAs($this->tree['users']['sl'])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('staffReport', null));
});

it('shows on a platoon\'s report whether its HQ staff sent theirs, to those who may read each', function () {
    sendOwnStaffReport($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);
    $pl = pageTrooperOf($this->tree['users']['pl']);
    $url = route('reports.show', ['unit' => $this->tree['units']['platoon']->id, 'week' => '2026-10-03']);

    $this->actingAs($this->tree['users']['co'])->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('staffReports', [
            ['trooperId' => $this->psg->id, 'name' => $this->psg->display_name, 'sent' => true, 'attended' => true, 'practices' => 1, 'href' => $this->url],
            ['trooperId' => $pl->id, 'name' => $pl->display_name, 'sent' => false, 'attended' => null, 'practices' => 0, 'href' => route('staff-report.show', ['trooper' => $pl->id, 'week' => '2026-10-03'])],
        ]));

    // The PL reads their own row only, not their PSG's.
    $this->actingAs($this->tree['users']['pl'])->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('staffReports', [
            ['trooperId' => $pl->id, 'name' => $pl->display_name, 'sent' => false, 'attended' => null, 'practices' => 0, 'href' => route('staff-report.show', ['trooper' => $pl->id, 'week' => '2026-10-03'])],
        ]));

    // A section's report has none.
    $this->actingAs($this->tree['users']['co'])->get(route('reports.show', ['unit' => $this->host->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('staffReports', null));
});
