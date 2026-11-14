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
use App\Milpac\StaffReportWeek;
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

/**
 * A stand-in for the reports sheet: records each row appended (answering with its range, as Google does) and each row
 * overwritten, or fails every write. Google is never called in a test.
 */
class FakeSheetsClient implements SheetsClient
{
    /** @var list<array{tab: string, row: list<string>}> */
    public array $rows = [];

    /** @var list<array{range: string, row: list<string>}> */
    public array $updates = [];

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

    public function append(string $tab, array $row): ?string
    {
        if ($this->fails) {
            throw GoogleSheetsUnavailable::httpError(503);
        }

        $this->rows[] = ['tab' => $tab, 'row' => $row];
        $line = count($this->rows) + 1;

        return "'{$tab}'!A{$line}:V{$line}";
    }

    public function update(string $range, array $row): void
    {
        if ($this->fails) {
            throw GoogleSheetsUnavailable::httpError(503);
        }

        $this->updates[] = ['range' => $range, 'row' => $row];
    }
}

beforeEach(function () {
    everyPracticeHeld();
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    // The Saturday after the fixture's week: 03OCT26 has ended and its report is due tonight.
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
        $fields['office_hour_date'] ?? null,
        $fields['office_hour_note'] ?? null,
        $fields['reason'] ?? null,
        // Presence is required: every send answers it, "No" unless the test says otherwise.
        presenceType: array_key_exists('presence_type', $fields) ? $fields['presence_type'] : 'none',
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

// Battalion HQ staff read their battalion (to set its accountability), so they read the HQ staff reports in it too: the
// sidebar opens "Staff reports", where their own report has its own card, as for battalion command.
it('lets battalion command file theirs too, and shows battalion HQ staff "Staff reports" in the sidebar', function () {
    $commander = Trooper::query()->where('cav_id', $this->battalionCo->cav_id)->sole();

    expect(sendFields($this->battalionCo, $commander, false, [])->unit_id)->toBe($this->tree['units']['battalion']->id);
    $this->actingAs($this->bnStaffUser)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('features.staffReports', 'overview'));
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

it('offers only the sections in their battalion, each a staff guest', function () {
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
                && collect($sections)->firstWhere('id', $far->id) === null));
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
            'presence_type' => 'none', 'attended' => true, 'section_ids' => [$this->host->id], 'notes' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors(['notes' => 'Keep the notes to 1000 characters.']);

    expect(StaffReport::query()->count())->toBe(0);
});

it('freezes the new fields with the report and reads them back on the page, the overview and the HQ panel', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'presence_type' => 'none', 'attended' => true, 'section_ids' => [], 'practice_type' => 'ftx', 'ftx_name' => 'Operation Anvil', 'ftx_date' => '2026-10-06',
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

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03')->assertInertia(fn (AssertableInertia $page) => $page
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
            '10/10/2026 12:00:00', 'Brandt.L', '1-7', 'Alpha', '', '09OCT26', 'I did not', 'Section Practice (SP)',
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
        '10/10/2026 12:00:00', 'Kerr.D', '1-7', 'Alpha', '', '09OCT26', 'I did not', 'FTX',
        '', '', '', '', '', 'Operation Anvil', '10/5/2026', 'Company', '', '', '', 'Company Commander', 'Led it.', '',
    ]]]);
});

it('maps a battalion HQ report to the battalion tab, with the battalion billet', function () {
    $fake = fakeSheet();
    $report = sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id], ['other_events' => 'Range day', 'notes' => 'Visited 1/A.']);

    pushToSheet($report);

    expect($fake->rows)->toBe([['tab' => 'Weekly Battalion Report 2O26', 'row' => [
        '10/10/2026 12:00:00', 'Stone.A', '1st Battalion', '09OCT26', 'Battalion XO', 'I did not', '1/1/A/1-7', 'Range day', 'Visited 1/A.',
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
            '10/10/2026 12:00:00', 'Reyes.M', 'Auxiliary Combat Detachment', '09OCT26', '', '', '', '', '',
            'ACD Commander', 'I did not', '', '', '', '', '',
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
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['presence_type' => 'none', 'attended' => true, 'section_ids' => [$this->host->id]])
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
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['presence_type' => 'none', 'attended' => true, 'section_ids' => [$this->host->id]])
        ->assertRedirect(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertSessionHasNoErrors();

    expect(StaffReport::query()->sole()->sheet_pushed_at)->toBeNull();
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03')->assertInertia(fn (AssertableInertia $page) => $page
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

// Accountability by HQ level: battalion HQ staff report an operation, a class or another official event.

it('takes an operation, a class or an event from battalion HQ staff, each with its name and a date in the week', function (string $type) {
    $report = sendFields($this->bnStaffUser, $this->bnStaff, true, [], ['practice_type' => $type, 'ftx_name' => ' Anvil ', 'ftx_date' => '2026-10-06']);

    expect($report->refresh()->practice_type)->toBe($type)
        ->and($report->ftx_name)->toBe('Anvil')
        ->and($report->ftx_date->toDateString())->toBe('2026-10-06')
        ->and($report->practices)->toBe([])
        ->and(StaffReportWeek::metRule(StaffReportWeek::reportProps($report)))->toBeTrue();
})->with(['operation', 'class', 'event']);

it('refuses a battalion HQ an FTX and platoon and company HQs an operation, class or event, writing nothing', function (string $who, string $type) {
    [$user, $trooper] = $who === 'bn'
        ? [$this->bnStaffUser, $this->bnStaff]
        : [$this->tree['users'][$who], Trooper::query()->where('cav_id', $this->tree['users'][$who]->cav_id)->sole()];

    expect(fieldsRefusal(fn () => sendFields($user, $trooper, true, [], ['practice_type' => $type, 'ftx_name' => 'Anvil', 'ftx_date' => '2026-10-06'])))
        ->toBe(['practice_type', 'practice_type'])
        ->and(StaffReport::query()->count())->toBe(0);
})->with([
    'FTX on a battalion HQ' => ['bn', 'ftx'],
    'operation on a platoon HQ' => ['psg', 'operation'],
    'class on a company HQ' => ['co', 'class'],
    'event on a platoon HQ' => ['psg', 'event'],
]);

it('refuses an office hour as accountability at every level, and through the page', function (string $who) {
    [$user, $trooper] = $who === 'bn'
        ? [$this->bnStaffUser, $this->bnStaff]
        : [$this->tree['users'][$who], Trooper::query()->where('cav_id', $this->tree['users'][$who]->cav_id)->sole()];

    expect(fieldsRefusal(fn () => sendFields($user, $trooper, true, [], ['practice_type' => 'office_hour'])))->toBe(['practice_type', 'practice_type']);

    $this->actingAs($user)
        ->post(route('staff-report.send', ['trooper' => $trooper->id, 'week' => '2026-10-03']), ['attended' => true, 'section_ids' => [], 'practice_type' => 'office_hour'])
        ->assertSessionHasErrors(['practice_type' => 'Pick what it was.']);

    expect(StaffReport::query()->count())->toBe(0);
})->with(['bn', 'psg', 'co']);

it('refuses an operation, class or event with a section, and its name and date with No', function () {
    expect(fieldsRefusal(fn () => sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id], ['practice_type' => 'class', 'ftx_name' => 'NCO class', 'ftx_date' => '2026-10-06'])))
        ->toBe(['practice_type', 'section_ids'])
        ->and(fieldsRefusal(fn () => sendFields($this->bnStaffUser, $this->bnStaff, false, [], ['practice_type' => 'operation', 'ftx_name' => 'Anvil'])))->toBe(['practice_type', 'practice_type'])
        ->and(fieldsRefusal(fn () => sendFields($this->bnStaffUser, $this->bnStaff, true, [], ['practice_type' => 'event'])))->toBe(['ftx', 'ftx_name']);
});

it('offers battalion HQ staff sections in their battalion only, and refuses one outside it', function () {
    $units = app(UnitRepository::class);
    $battalion2 = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->tree['units']['regiment']->id);
    $platoon2 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/2-7', $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $battalion2->id)->id);
    $far = $units->create(UnitType::Section, '1st Section', '1/1/A/2-7', $platoon2->id);
    Trooper::factory()->create(['primary_section_id' => $far->id]);

    expect(fieldsRefusal(fn () => sendFields($this->bnStaffUser, $this->bnStaff, true, [$far->id])))->toBe(['not_in_unit', 'section_ids'])
        ->and(sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id])->practices)->toEqual([['section_id' => $this->host->id, 'section' => '1/1/A/1-7']]);
});

it('reads back an office hour or FTX sent as accountability before, as sent', function () {
    $report = StaffReport::query()->create([
        'trooper_id' => $this->bnStaff->id, 'unit_id' => $this->tree['units']['battalion']->id, 'week_start' => staffPracticeWeek(), 'attended' => true,
        'practice_type' => 'office_hour', 'office_hour_date' => '2026-10-06', 'office_hour_note' => 'Q and A', 'practices' => [],
        'sent_at' => CarbonImmutable::now('UTC'), 'sent_by' => $this->bnStaffUser->id,
    ]);

    $this->actingAs($this->regimentCo)->get(route('staff-report.show', ['trooper' => $this->bnStaff->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.practiceType', 'office_hour')
            ->where('report.officeHour', ['date' => '2026-10-06', 'note' => 'Q and A'])
            ->where('report.presenceType', null)
            ->where('report.publicServer', null));

    expect(StaffReportWeek::metRule(StaffReportWeek::reportProps($report)))->toBeTrue();

    $fake = fakeSheet();
    pushToSheet($report);
    // As it showed before: the accountability Yes under "Did you spend one hour", the office hour in the other events.
    expect($fake->rows[0]['row'][5])->toBe('Yes')
        ->and($fake->rows[0]['row'][7])->toBe('Office hour (10/6/2026): Q and A');
});

it('puts an operation at the front of the other events cell on the battalion tab, and the office hour under "Did you spend one hour"', function () {
    $fake = fakeSheet();
    $report = sendFields($this->bnStaffUser, $this->bnStaff, true, [], [
        'practice_type' => 'operation', 'ftx_name' => 'Anvil', 'ftx_date' => '2026-10-06', 'other_events' => 'Range day',
        'presence_type' => 'office_hour', 'office_hour_date' => '2026-10-07', 'office_hour_note' => 'Q and A',
    ]);

    pushToSheet($report);

    expect($fake->rows)->toBe([['tab' => 'Weekly Battalion Report 2O26', 'row' => [
        '10/10/2026 12:00:00', 'Stone.A', '1st Battalion', '09OCT26', 'Battalion XO', 'Yes, office hour (10/7/2026): Q and A', '', 'Operation: Anvil (10/6/2026); Range day', '',
        '', '', '', '', '', '', '',
    ]]]);
});

// Presence, all three levels: a public server hour, an office hour, or neither.

it('takes each presence answer from every level, an office hour with its date and note', function (string $who) {
    [$user, $trooper] = $who === 'bn'
        ? [$this->bnStaffUser, $this->bnStaff]
        : [$this->tree['users'][$who], Trooper::query()->where('cav_id', $this->tree['users'][$who]->cav_id)->sole()];

    $report = sendFields($user, $trooper, true, [$this->host->id], ['presence_type' => 'office_hour', 'office_hour_date' => '2026-10-06', 'office_hour_note' => ' Questions from new NCOs ']);

    expect($report->refresh()->presence_type)->toBe('office_hour')
        ->and($report->practice_type)->toBe('sp')
        ->and($report->office_hour_date->toDateString())->toBe('2026-10-06')
        ->and($report->office_hour_note)->toBe('Questions from new NCOs')
        // An office hour is not checked.
        ->and($report->public_server_check)->toBeNull();
})->with(['bn', 'psg', 'co']);

it('takes "No" to presence, and refuses a send or an edit with no presence answer', function () {
    expect(fieldsRefusal(fn () => sendFields($this->bnStaffUser, $this->bnStaff, false, [], ['presence_type' => null])))->toBe(['presence', 'presence_type'])
        ->and(StaffReport::query()->count())->toBe(0);

    expect(sendFields($this->tree['users']['psg'], $this->psg, false, [])->refresh()->presence_type)->toBe('none');
    expect(fieldsRefusal(fn () => sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], ['presence_type' => null, 'reason' => 'Went after all'])))->toBe(['presence', 'presence_type']);

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), ['attended' => true, 'section_ids' => [$this->host->id], 'reason' => 'Went after all'])
        ->assertSessionHasErrors(['presence_type' => 'Answer the presence question.']);
});

it('refuses an office hour without a note, with a date outside the week, over 200 characters, or its fields elsewhere, and an unknown presence', function (array $fields, array $refusal) {
    expect(fieldsRefusal(fn () => sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], $fields)))->toBe($refusal)
        ->and(StaffReport::query()->count())->toBe(0);
})->with([
    'no note' => [['presence_type' => 'office_hour', 'office_hour_date' => '2026-10-06'], ['office_hour', 'office_hour_note']],
    'no date' => [['presence_type' => 'office_hour', 'office_hour_note' => 'Q and A'], ['office_hour', 'office_hour_date']],
    'the Saturday after' => [['presence_type' => 'office_hour', 'office_hour_date' => '2026-10-10', 'office_hour_note' => 'Q and A'], ['office_hour', 'office_hour_date']],
    'note too long' => [['presence_type' => 'office_hour', 'office_hour_date' => '2026-10-06', 'office_hour_note' => str_repeat('a', 201)], ['too_long', 'office_hour_note']],
    'note with a public server hour' => [['presence_type' => 'public_server', 'office_hour_note' => 'Q and A'], ['office_hour', 'office_hour_note']],
    'note with No' => [['presence_type' => 'none', 'office_hour_date' => '2026-10-06'], ['office_hour', 'office_hour_note']],
    'unknown presence' => [['presence_type' => 'forum'], ['presence', 'presence_type']],
]);

it('takes an office hour note of exactly 200 characters, and bounds the request at the controller', function () {
    expect(mb_strlen(sendFields($this->bnStaffUser, $this->bnStaff, true, [$this->host->id], ['presence_type' => 'office_hour', 'office_hour_date' => '2026-10-09', 'office_hour_note' => str_repeat('a', 200)])->office_hour_note))->toBe(200);

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [$this->host->id], 'presence_type' => 'office_hour', 'office_hour_date' => '2026-10-06', 'office_hour_note' => str_repeat('a', 201),
        ])
        ->assertSessionHasErrors(['office_hour_note' => 'Keep the office hour note to 200 characters.']);

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [$this->host->id], 'presence_type' => 'forum',
        ])
        ->assertSessionHasErrors(['presence_type' => 'Pick Public server hour, Office hour or No.']);
});

it('freezes the presence answer and reads it back on the page and the overview', function () {
    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [$this->host->id], 'presence_type' => 'office_hour', 'office_hour_date' => '2026-10-06', 'office_hour_note' => 'Q and A',
        ])
        ->assertRedirect();

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.presenceType', 'office_hour')
            ->where('report.officeHour', ['date' => '2026-10-06', 'note' => 'Q and A'])
            ->where('report.publicServer', null)
            ->where('limits.officeHourNote', 200));

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('groups.0.rows', fn ($rows) => ($row = collect($rows)->firstWhere('trooperId', $this->psg->id)) !== null
            && $row['presenceType'] === 'office_hour' && $row['officeHour'] === ['date' => '2026-10-06', 'note' => 'Q and A'] && $row['publicServer'] === null));
});

it('fills "Did you spend one hour" with the presence answer on the staff tab, keeping other events in column V', function () {
    $fake = fakeSheet();
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], ['presence_type' => 'public_server', 'other_events' => 'Range day']);

    pushToSheet($report);

    expect($fake->rows[0]['row'][6])->toBe('Yes, public server')
        ->and($fake->rows[0]['row'][21])->toBe('Range day');
});

it('overwrites the row an edited report went to, instead of adding a second one', function () {
    $fake = fakeSheet();
    $report = sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id], ['notes' => 'All good.']);
    pushToSheet($report);

    expect($report->refresh()->sheet_range)->toBe("'Weekly Staff Report 2O26'!A2:V2");

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 18:00:00', 'UTC'));
    $edited = sendFields($this->tree['users']['psg'], $this->psg, false, [], ['reason' => 'Missed it after all.']);

    expect($edited->refresh()->sheet_pushed_at)->toBeNull()
        ->and(pushToSheet($edited))->toBeTrue()
        ->and($fake->rows)->toHaveCount(1)
        ->and($fake->updates)->toHaveCount(1)
        ->and($fake->updates[0]['range'])->toBe("'Weekly Staff Report 2O26'!A2:V2")
        ->and($fake->updates[0]['row'][0])->toBe('10/10/2026 18:00:00')
        ->and($fake->updates[0]['row'][6])->toBe('I did not')
        ->and($edited->refresh()->sheet_pushed_at?->toIso8601String())->toBe('2026-10-10T18:00:00+00:00')
        ->and($edited->sheet_range)->toBe("'Weekly Staff Report 2O26'!A2:V2")
        // Done once: a retry writes nothing more.
        ->and(pushToSheet($edited))->toBeFalse()
        ->and($fake->updates)->toHaveCount(1);
});

it('appends an edited report whose first push never went out, once', function () {
    $fake = fakeSheet(fails: true);
    sendFields($this->tree['users']['psg'], $this->psg, true, [$this->host->id]);

    $fake->fails = false;
    $edited = sendFields($this->tree['users']['psg'], $this->psg, false, [], ['reason' => 'Missed it after all.']);

    expect(pushToSheet($edited))->toBeTrue()
        ->and($fake->rows)->toHaveCount(1)
        ->and($fake->rows[0]['row'][6])->toBe('I did not')
        ->and($fake->updates)->toBe([]);
});

it('queues the push again when the report is edited', function () {
    $fake = fakeSheet();
    $url = route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);

    $this->actingAs($this->tree['users']['psg'])->post($url, ['presence_type' => 'none', 'attended' => true, 'section_ids' => [$this->host->id]])->assertRedirect();
    $this->actingAs($this->tree['users']['psg'])
        ->post($url, ['presence_type' => 'none', 'attended' => false, 'section_ids' => [], 'reason' => 'Missed it after all.'])
        ->assertSessionHasNoErrors();

    expect($fake->rows)->toHaveCount(1)
        ->and($fake->updates)->toHaveCount(1)
        ->and(StaffReport::query()->sole()->sheet_pushed_at)->not->toBeNull();
});
