<?php

use App\Commands\StaffReports\PushStaffReportToSheet;
use App\Commands\StaffReports\PushStaffReportToSheetHandler;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Commands\StaffReports\StaffReportSheetRow;
use App\Integration\GoogleSheets\GoogleSheetsUnavailable;
use App\Integration\GoogleSheets\SheetsClient;
use App\Jobs\PushStaffReportToSheetJob;
use App\Models\AuditLogEntry;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// Battalion HQ staff file the weekly staff report too, the report carries the rest of what the regiment's Google Forms
// asked (practice type, FTX, other events, notes), and a sent report is copied to the reports Google Sheet.

/** A stand-in for the reports sheet: records each row, or fails every append. Google is never called in a test. */
class FakeSheetsClient implements SheetsClient
{
    /** @var list<array{tab: string, row: list<string>}> */
    public array $rows = [];

    public function __construct(public bool $configured = true, public bool $fails = false) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function staffTab(): string
    {
        return 'Weekly Staff Report 2O26';
    }

    public function battalionTab(): string
    {
        return 'Weekly Battalion Report 2O26';
    }

    public function append(string $tab, array $row): void
    {
        if ($this->fails) {
            throw GoogleSheetsUnavailable::httpError(503);
        }

        $this->rows[] = ['tab' => $tab, 'row' => $row];
    }
}

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    // The Saturday after the fixture's week: 03Oct26 has ended and its report is due tonight.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->host = $this->tree['units']['host'];
    $this->psg = $this->tree['troopers']['psg'];
    $this->psg->forceFill(['billet' => 'Platoon Sergeant'])->save();
    $this->regimentCo = User::factory()->commanding($this->tree['units']['regiment'], ['primary_section_id' => $this->tree['units']['regiment']->id])->create();
    $this->battalionCo = User::factory()->commanding($this->tree['units']['battalion'], ['primary_section_id' => $this->tree['units']['battalion']->id])->create();
    // A battalion staff member who is not in command: the battalion XO, say.
    $this->bnStaff = Trooper::factory()->create(['primary_section_id' => $this->tree['units']['battalion']->id, 'username' => 'Stone.A', 'billet' => 'Battalion XO']);
    $this->bnStaffUser = User::factory()->create(['cav_id' => $this->bnStaff->cav_id, 'username' => 'Stone.A']);
});

/** Send `$trooper`'s report for the fixture's week, as `$actor`, with the new fields named. */
function sendFields(User $actor, Trooper $trooper, bool $attended, array $sectionIds, array $fields = []): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($actor, new SendStaffReport(
        $trooper->id,
        staffPracticeWeek(),
        $attended,
        $sectionIds,
        $fields['practice_type'] ?? null,
        $fields['ftx_name'] ?? null,
        $fields['ftx_date'] ?? null,
        $fields['other_events'] ?? null,
        $fields['notes'] ?? null,
    ));
}

function fieldsRefusal(Closure $write): array
{
    try {
        $write();
    } catch (StaffReportRefusedException $e) {
        return [$e->reason, $e->field];
    }

    throw new RuntimeException('Expected the staff report to be refused.');
}

function fakeSheet(bool $configured = true, bool $fails = false): FakeSheetsClient
{
    $fake = new FakeSheetsClient($configured, $fails);
    app()->instance(SheetsClient::class, $fake);

    return $fake;
}

function pushToSheet(StaffReport $report): bool
{
    return app(PushStaffReportToSheetHandler::class)->handle(new PushStaffReportToSheet($report->id));
}

// Battalion HQ staff.

it('lets battalion HQ staff send their own report, from the battalion HQ', function () {
    $report = sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id]);

    expect($report->unit_id)->toBe($this->tree['units']['battalion']->id)
        ->and(AuditLogEntry::query()->where('action', 'staff_report.sent')->count())->toBe(1);
});

it('lets battalion command file theirs too, and shows them "Staff report" in the sidebar', function () {
    $commander = Trooper::query()->where('cav_id', $this->battalionCo->cav_id)->sole();

    expect(sendFields($this->battalionCo, $commander, false, [])->unit_id)->toBe($this->tree['units']['battalion']->id);
    $this->actingAs($this->bnStaffUser)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('features.staffReports', 'own'));
});

it('lets regiment command and Admins read a battalion HQ staff report, and not battalion command or a company commander', function () {
    sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id]);
    $url = route('staff-report.show', ['trooper' => $this->bnStaff->id, 'week' => '2026-10-03']);

    $this->actingAs($this->regimentCo)->get($url)->assertOk();
    $this->actingAs($this->tree['users']['admin'])->get($url)->assertOk();
    $this->actingAs($this->battalionCo)->get($url)->assertNotFound();
    $this->actingAs($this->tree['users']['co'])->get($url)->assertNotFound();
});

it('lists the battalion HQ on the overview for regiment command and Admins only', function () {
    $labels = fn (User $user) => collect($this->actingAs($user)->get('/staff-reports')->assertOk()->viewData('page')['props']['groups'])->pluck('label')->all();

    expect($labels($this->regimentCo))->toContain('1-7')
        ->and($labels($this->tree['users']['admin']))->toContain('1-7')
        ->and($labels($this->battalionCo))->not->toContain('1-7');
});

it('calls a practice in their battalion a staff guest, and one outside it a guest', function () {
    $units = app(UnitRepository::class);
    $battalion2 = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->tree['units']['regiment']->id);
    $company2 = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $battalion2->id);
    $platoon2 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/2-7', $company2->id);
    $far = $units->create(UnitType::Section, '1st Section', '1/1/A/2-7', $platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $far->id]);
    Trooper::factory()->create(['primary_section_id' => $this->host->id]);

    $this->actingAs($this->bnStaffUser)->get(route('staff-report.show', ['trooper' => $this->bnStaff->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', fn ($sections) => collect($sections)->firstWhere('id', $this->host->id)['own'] === true
                && collect($sections)->firstWhere('id', $far->id)['own'] === false));
});

// The new fields.

it('defaults a Yes to an SP, and keeps No without a type', function () {
    expect(sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id])->practice_type)->toBe('sp');
    expect(sendFields($this->bnStaffUser, $this->bnStaff, false, [])->practice_type)->toBeNull();
});

it('takes an FTX with its name and a date in the week, and no section', function () {
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [], ['practice_type' => 'ftx', 'ftx_name' => ' Operation Anvil ', 'ftx_date' => '2026-10-05']);

    expect($report->refresh()->practice_type)->toBe('ftx')
        ->and($report->ftx_name)->toBe('Operation Anvil')
        ->and($report->ftx_date->toDateString())->toBe('2026-10-05')
        ->and($report->practices)->toBe([]);
});

it('refuses an FTX without a name, or with a date outside the Cav week', function (array $fields, string $field) {
    expect(fieldsRefusal(fn () => sendFields($this->tree['users']['psg'], $this->psg, true, [], ['practice_type' => 'ftx', ...$fields])))->toBe(['ftx', $field])
        ->and(StaffReport::query()->count())->toBe(0);
})->with([
    'no name' => [['ftx_date' => '2026-10-05'], 'ftx_name'],
    'no date' => [['ftx_name' => 'Operation Anvil'], 'ftx_date'],
    'the Friday before' => [['ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-02'], 'ftx_date'],
    'the Saturday after' => [['ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-10'], 'ftx_date'],
    'not a date' => [['ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-02-31'], 'ftx_date'],
]);

it('still needs a section for an SP, refuses FTX fields on an SP or with No, and an unknown type', function () {
    $psg = $this->tree['users']['psg'];

    expect(fieldsRefusal(fn () => sendFields($psg, $this->psg, true, [])))->toBe(['no_practice', 'section_ids'])
        ->and(fieldsRefusal(fn () => sendFields($psg, $this->psg, true, [$this->host->id], ['ftx_name' => 'Anvil'])))->toBe(['ftx', 'ftx_name'])
        ->and(fieldsRefusal(fn () => sendFields($psg, $this->psg, false, [], ['practice_type' => 'ftx'])))->toBe(['practice_type', 'practice_type'])
        ->and(fieldsRefusal(fn () => sendFields($psg, $this->psg, true, [$this->host->id], ['practice_type' => 'drill'])))->toBe(['practice_type', 'practice_type']);
});

it('holds the FTX name to 120 characters, other events to 500 and notes to 1000', function (array $fields, string $field) {
    expect(fieldsRefusal(fn () => sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], $fields)))->toBe(['too_long', $field]);
})->with([
    'FTX name' => [['practice_type' => 'ftx', 'ftx_name' => str_repeat('a', 121), 'ftx_date' => '2026-10-05'], 'ftx_name'],
    'other events' => [['other_events' => str_repeat('a', 501)], 'other_events'],
    'notes' => [['notes' => str_repeat('a', 1001)], 'notes'],
]);

it('takes the limits exactly, and empty text as nothing', function () {
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], ['other_events' => str_repeat('a', 500), 'notes' => '   ']);

    expect(mb_strlen($report->other_events))->toBe(500)->and($report->notes)->toBeNull();
});

it('bounds the request itself too, before the handler', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [$this->host->id], 'notes' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors(['notes' => 'Keep the notes to 1000 characters.']);

    expect(StaffReport::query()->count())->toBe(0);
});

it('freezes the new fields with the report and reads them back on the page, the overview and the HQ panel', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [], 'practice_type' => 'ftx', 'ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-06',
            'other_events' => 'Range day', 'notes' => 'Ran the OPFOR.',
        ])
        ->assertRedirect();

    $row = StaffReport::query()->sole();
    expect([$row->practice_type, $row->ftx_name, $row->ftx_date->toDateString(), $row->other_events, $row->notes])
        ->toBe(['ftx', 'Operation Anvil', '2026-10-06', 'Range day', 'Ran the OPFOR.']);

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.practiceType', 'ftx')
            ->where('report.ftx', ['name' => 'Operation Anvil', 'date' => '2026-10-06'])
            ->where('report.otherEvents', 'Range day')
            ->where('report.notes', 'Ran the OPFOR.'));

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('groups.0.rows', fn ($rows) => ($psg = collect($rows)->firstWhere('trooperId', $this->psg->id)) !== null
            && $psg['practiceType'] === 'ftx' && $psg['otherEvents'] === 'Range day' && $psg['notes'] === 'Ran the OPFOR.'
            // An FTX keeps to the rule of one practice a week, sections or not.
            && $psg['metRule'] === true));

    $this->actingAs($this->tree['users']['co'])->get(route('reports.show', ['unit' => $this->tree['units']['platoon']->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('staffReports', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['ftx'] === ['name' => 'Operation Anvil', 'date' => '2026-10-06']));
});

// The reports Google Sheet.

it('maps a platoon HQ report to the staff tab, column by column', function () {
    $fake = fakeSheet();
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id, $this->tree['units']['other']->id], ['other_events' => 'Range day', 'notes' => 'All good.']);

    expect(pushToSheet($report))->toBeTrue()
        ->and(StaffReportSheetRow::STAFF_PRACTICE_COLUMNS)->toBe(5)
        ->and($fake->rows)->toBe([['tab' => 'Weekly Staff Report 2O26', 'row' => [
            '10/10/2026 12:00:00', 'Brandt.L', '1-7', 'Alpha', '', '09Oct26', 'Yes', 'Section Practice (SP)',
            '1/1/A/1-7, 2/1/A/1-7', '', '', '', '', '', '', 'Platoon', 'Platoon Sergeant', '1st Platoon', 'All good.', '', '', 'Range day',
        ]]]);
});

it('maps a company HQ FTX report to the staff tab, with the company billet and notes', function () {
    $fake = fakeSheet();
    $co = Trooper::query()->where('cav_id', $this->tree['users']['co']->cav_id)->sole();
    $co->forceFill(['username' => 'Kerr.D', 'billet' => 'Company Commander'])->save();
    $report = sendFields($this->tree['users']['co'], $co, true, [], ['practice_type' => 'ftx', 'ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-05', 'notes' => 'Led it.']);

    pushToSheet($report);

    expect($fake->rows)->toBe([['tab' => 'Weekly Staff Report 2O26', 'row' => [
        '10/10/2026 12:00:00', 'Kerr.D', '1-7', 'Alpha', '', '09Oct26', 'Yes', 'FTX',
        '', '', '', '', '', 'Operation Anvil', '10/5/2026', 'Company', '', '', '', 'Company Commander', 'Led it.', '',
    ]]]);
});

it('maps a battalion HQ report to the battalion tab, with the battalion billet', function () {
    $fake = fakeSheet();
    $report = sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id], ['other_events' => 'Range day', 'notes' => 'Visited 1/A.']);

    pushToSheet($report);

    expect($fake->rows)->toBe([['tab' => 'Weekly Battalion Report 2O26', 'row' => [
        '10/10/2026 12:00:00', 'Stone.A', '1st Battalion', '09Oct26', 'Battalion XO', 'Yes', '1/1/A/1-7', 'Range day', 'Visited 1/A.',
        '', '', '', '', '', '', '',
    ]]]);
});

it('maps an ACD HQ report to the battalion tab, with the billet in ACD Billet', function () {
    $fake = fakeSheet();
    $acd = app(UnitRepository::class)->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', $this->tree['units']['regiment']->id);
    $trooper = Trooper::factory()->create(['primary_section_id' => $acd->id, 'username' => 'Reyes.M', 'billet' => 'ACD Commander']);
    $user = User::factory()->create(['cav_id' => $trooper->cav_id]);
    $report = sendFields($user, $trooper, false, []);

    pushToSheet($report);

    expect($report->unit_id)->toBe($acd->id)
        ->and($fake->rows)->toBe([['tab' => 'Weekly Battalion Report 2O26', 'row' => [
            '10/10/2026 12:00:00', 'Reyes.M', 'Auxiliary Combat Detachment', '09Oct26', '', '', '', '', '',
            'ACD Commander', 'No', '', '', '', '', '',
        ]]]);
});

it('pushes nothing and fails nothing without a configured sheet', function () {
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    // The real seam, with the env keys empty as phpunit.xml pins them.
    expect(app(SheetsClient::class)->isConfigured())->toBeFalse()
        ->and(pushToSheet($report))->toBeFalse()
        ->and($report->refresh()->sheet_pushed_at)->toBeNull();

    $fake = fakeSheet(configured: false);
    expect(pushToSheet($report))->toBeFalse()->and($fake->rows)->toBe([]);
});

it('queues the push on send, and marks the report sent to the sheet', function () {
    $fake = fakeSheet();

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['attended' => true, 'section_ids' => [$this->host->id]])
        ->assertRedirect();

    $report = StaffReport::query()->sole();
    expect($fake->rows)->toHaveCount(1)
        ->and($report->sheet_pushed_at?->toIso8601String())->toBe('2026-10-10T12:00:00+00:00')
        // A second run (a retry after a success it did not see) adds no second row.
        ->and(pushToSheet($report))->toBeFalse()
        ->and($fake->rows)->toHaveCount(1);

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sheetConfigured', true)->where('report.sheetPushedAt', '2026-10-10T12:00:00+00:00'));
});

it('keeps the send when the push fails, leaves the report not sent to the sheet, and retries the job', function () {
    fakeSheet(fails: true);

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['attended' => true, 'section_ids' => [$this->host->id]])
        ->assertRedirect(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertSessionHasNoErrors();

    expect(StaffReport::query()->sole()->sheet_pushed_at)->toBeNull();
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sheetConfigured', true)
        ->where('groups.0.rows', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['sheetPushedAt'] === null));

    $job = new PushStaffReportToSheetJob(new PushStaffReportToSheet(StaffReport::query()->sole()->id));
    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([60, 300, 900, 3600]);
    expect(fn () => $job->handle(app(PushStaffReportToSheetHandler::class)))->toThrow(GoogleSheetsUnavailable::class);
});

it('says nothing about the sheet when none is set up', function () {
    sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sheetConfigured', false)->where('report.sheetPushedAt', null));
});

it('keeps the row builder to a battalion for ACD only by its designation', function () {
    $acd = new Unit(['type' => UnitType::Battalion, 'name' => 'Auxiliary Combat Detachment', 'callsign' => 'ACD']);
    $bn = new Unit(['type' => UnitType::Battalion, 'name' => '1st Battalion', 'callsign' => '1-7']);

    expect(StaffReportSheetRow::isAcd($acd))->toBeTrue()->and(StaffReportSheetRow::isAcd($bn))->toBeFalse();
});
