<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeHeldState;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A staff report claims only section practices that were held: the picker greys out one still to come or not held,
// and says why, and the send refuses it. The staff report pages open on the running week and warn about a report
// still owed for the week before, unless the staff member was on leave then.

beforeEach(function () {
    // Thursday 08OCT26 2000Z, in the Cav week of 03OCT26.
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    $this->psg = $this->tree['troopers']['psg'];
    $this->host = $this->tree['units']['host'];
    $this->other = $this->tree['units']['other'];
});

function heldSend(User $actor, Trooper $trooper, array $sectionIds, string $type = 'sp', ?string $week = null): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($actor, new SendStaffReport(
        $trooper->id,
        CarbonImmutable::parse($week ?? '2026-10-03', 'UTC'),
        true,
        $sectionIds,
        $type,
        $type === 'ftx' ? 'Range day' : null,
        $type === 'ftx' ? '2026-10-04' : null,
        presenceType: 'none',
    ));
}

function heldRefusal(Closure $send): StaffReportRefusedException
{
    try {
        $send();
    } catch (StaffReportRefusedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the staff report to be refused.');
}

/** A slot for the section: weekday 3 is Wednesday, 5 Friday; 1200 is 2000Z. */
function heldSlot(int $sectionId, int $weekday, int $startMinute = 1200): void
{
    SectionPracticeSlot::query()->create(['section_id' => $sectionId, 'weekday' => $weekday, 'start_minute' => $startMinute]);
}

/** A leader's Present mark on the section's practice that week, which holds it once its hour is over. */
function heldMark(int $sectionId, string $week = '2026-10-03', AttendanceStatus $status = AttendanceStatus::Present, AttendanceSource $source = AttendanceSource::Manual): SectionPractice
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($sectionId, CarbonImmutable::parse($week, 'UTC'));
    Attendance::factory()->create([
        'trooper_id' => Trooper::factory()->create(['primary_section_id' => $sectionId])->id,
        'section_practice_id' => $practice->id,
        'status' => $status,
        'source' => $source,
    ]);

    return $practice;
}

function heldStatesNow(array $sectionIds, string $week = '2026-10-03'): array
{
    return app(SectionPracticeRepository::class)->heldStates($sectionIds, CarbonImmutable::parse($week, 'UTC'));
}

it('reads each section\'s practice as held, still to come, not marked held, not held, or with no practice time', function () {
    $units = app(UnitRepository::class);
    $platoon = $this->tree['units']['platoon'];
    [$third, $fourth, $fifth] = [
        $units->create(UnitType::Section, '3rd Section', '3/1/A/1-7', $platoon->id),
        $units->create(UnitType::Section, '4th Section', '4/1/A/1-7', $platoon->id),
        $units->create(UnitType::Section, '5th Section', '5/1/A/1-7', $platoon->id),
    ];
    // Over on Wednesday, with a mark: held.
    heldSlot($this->host->id, 3);
    heldMark($this->host->id);
    // Friday's practice has not come yet.
    heldSlot($this->other->id, 5);
    // Over on Wednesday, nobody marked it.
    heldSlot($third->id, 3);
    // Confirmed as not held, mark or not.
    heldSlot($fourth->id, 3);
    heldMark($fourth->id)->forceFill(['confirmed_at' => now(), 'not_held' => true])->save();

    expect(heldStatesNow([$this->host->id, $this->other->id, $third->id, $fourth->id, $fifth->id]))->toBe([
        $this->host->id => PracticeHeldState::HELD,
        $this->other->id => PracticeHeldState::UPCOMING,
        $third->id => PracticeHeldState::UNMARKED,
        $fourth->id => PracticeHeldState::NOT_HELD,
        // No slot and nothing confirmed.
        $fifth->id => PracticeHeldState::NO_SLOT,
    ]);
});

it('reads a practice confirmed as held as held, slot or not, and a cancelled week as not held', function () {
    confirmPracticeHeld($this->host, '2026-10-03');
    heldSlot($this->other->id, 3);
    app(SectionPracticeRepository::class)->findOrCreate($this->other->id, $this->week)->forceFill(['slot_cancelled' => true])->save();

    expect(heldStatesNow([$this->host->id, $this->other->id]))->toBe([
        $this->host->id => PracticeHeldState::HELD,
        $this->other->id => PracticeHeldState::NOT_HELD,
    ]);
});

it('does not count LOA, Excused or an automatic makeup as a mark that holds a practice', function (AttendanceStatus $status, AttendanceSource $source) {
    heldSlot($this->host->id, 3);
    heldMark($this->host->id, status: $status, source: $source);

    expect(heldStatesNow([$this->host->id]))->toBe([$this->host->id => PracticeHeldState::UNMARKED]);
})->with([
    'LOA' => [AttendanceStatus::Loa, AttendanceSource::Manual],
    'Excused' => [AttendanceStatus::Excused, AttendanceSource::Manual],
    'an automatic makeup' => [AttendanceStatus::Makeup, AttendanceSource::Auto],
]);

it('refuses a section practice that was not held, saying why, and writes nothing', function (Closure $arrange, string $message) {
    $arrange($this);

    $refusal = heldRefusal(fn () => heldSend($this->tree['users']['psg'], $this->psg, [$this->other->id]));

    expect($refusal->reason)->toBe('not_held')
        ->and($refusal->field)->toBe('section_ids')
        ->and($refusal->getMessage())->toBe($message)
        ->and(StaffReport::query()->count())->toBe(0);
})->with([
    'still to come' => [fn ($test) => heldSlot($test->other->id, 5), 'The practice of 2/1/A/1-7 has not happened yet. You can tick it once it is held.'],
    'over and not marked held' => [fn ($test) => heldSlot($test->other->id, 3), 'No SP held at 2/1/A/1-7 yet. You can tick it once it is held.'],
    'confirmed as not held' => [function ($test) {
        heldSlot($test->other->id, 3);
        heldMark($test->other->id)->forceFill(['confirmed_at' => now(), 'not_held' => true])->save();
    }, 'No SP was held at 2/1/A/1-7 this week, so it cannot be ticked.'],
    'no practice time set' => [fn ($test) => null, '2/1/A/1-7 has no practice time set, so there is no practice to tick.'],
]);

it('takes a held practice, and refuses the whole report when one pick beside it was not held', function () {
    heldSlot($this->host->id, 3);
    heldMark($this->host->id);
    heldSlot($this->other->id, 5);

    expect(heldRefusal(fn () => heldSend($this->tree['users']['psg'], $this->psg, [$this->host->id, $this->other->id]))->reason)->toBe('not_held')
        ->and(StaffReport::query()->count())->toBe(0);

    expect(heldSend($this->tree['users']['psg'], $this->psg, [$this->host->id])->practices)->toEqual([['section_id' => $this->host->id, 'section' => '1/1/A/1-7']]);
});

it('does not check the sections of an FTX, which name who took part', function () {
    expect(heldSend($this->tree['users']['psg'], $this->psg, [$this->other->id], 'ftx')->practice_type)->toBe('ftx');
});

it('refuses an edit that keeps a pick that was not held, and leaves the sent report as it was', function () {
    heldSlot($this->host->id, 3);
    heldMark($this->host->id);
    heldSend($this->tree['users']['psg'], $this->psg, [$this->host->id]);
    // A report sent before the rule, claiming a practice still to come, stays as it was sent.
    StaffReport::query()->update(['practices' => [['section_id' => $this->other->id, 'section' => '2/1/A/1-7']]]);
    heldSlot($this->other->id, 5);

    $refusal = heldRefusal(fn () => app(SendStaffReportHandler::class)->handle($this->tree['users']['psg'], new SendStaffReport(
        $this->psg->id, $this->week, true, [$this->other->id], 'sp', reason: 'Fix the picks', presenceType: 'none',
    )));

    expect($refusal->reason)->toBe('not_held')
        ->and(StaffReport::query()->sole()->practices)->toEqual([['section_id' => $this->other->id, 'section' => '2/1/A/1-7']]);
});

it('answers a refused pick on the page as an error on the picker', function () {
    heldSlot($this->other->id, 5);

    $this->actingAs($this->tree['users']['psg'])
        ->post(route('staff-report.send', ['trooper' => $this->psg->id, 'week' => '2026-10-03']), [
            'attended' => true, 'section_ids' => [$this->other->id], 'practice_type' => 'sp', 'presence_type' => 'none',
        ])
        ->assertSessionHasErrors(['section_ids' => 'The practice of 2/1/A/1-7 has not happened yet. You can tick it once it is held.']);
});

it('tells the picker which practices were held, and why the others cannot be ticked', function () {
    heldSlot($this->host->id, 3);
    heldMark($this->host->id);
    heldSlot($this->other->id, 5);
    // A section nobody is in is not offered (#442).
    manUnits($this->other);

    $this->actingAs($this->tree['users']['psg'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections.0.id', $this->host->id)
            ->where('sections.0.held', 'held')
            ->where('sections.1.id', $this->other->id)
            ->where('sections.1.held', 'upcoming'));
});

it('opens the staff member\'s own report on the running week and warns while last week\'s is owed', function () {
    // Saturday 10OCT26: the week of 03OCT26 has ended and its report is not sent.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $running = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-10']);

    $this->actingAs($this->tree['users']['psg'])->get('/staff-report')->assertRedirect($running);
    $this->actingAs($this->tree['users']['psg'])->get($running)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('week.owed', '2026-10-03')
        ->where('canSend', true));

    // Past its deadline it is still owed, and still warned about.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
    $this->actingAs($this->tree['users']['psg'])->get('/staff-report')->assertRedirect($running);
    $this->actingAs($this->tree['users']['psg'])->get($running)->assertInertia(fn (AssertableInertia $page) => $page->where('week.owed', '2026-10-03'));

    // A reader of the same page is not told what the staff member owes.
    $this->actingAs($this->tree['users']['co'])->get($running)->assertInertia(fn (AssertableInertia $page) => $page->where('week.owed', null));
});

it('does not warn once last week is sent, or when their leave covered it', function (Closure $arrange) {
    $arrange($this);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

    $this->actingAs($this->tree['users']['psg'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-10']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('week.owed', null));
})->with([
    'sent' => [fn ($test) => app(SendStaffReportHandler::class)->handle($test->tree['users']['psg'], new SendStaffReport($test->psg->id, $test->week, false, [], presenceType: 'none'))],
    'on LOA that week' => [fn ($test) => Loa::factory()->create(['trooper_id' => $test->psg->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-07'])],
]);

it('opens the overview on the running week and marks the staff who still owe last week, leave excepted', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $pl = Trooper::query()->where('cav_id', $this->tree['users']['pl']->cav_id)->sole();
    Loa::factory()->create(['trooper_id' => $pl->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-07']);

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('week.date', '2026-10-10')
        ->where('owed', ['week' => '2026-10-03', 'count' => 1])
        // The CO owes last week's too, on their own line.
        ->where('own.owesPrevious', true)
        ->where('groups.0.rows', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['owesPrevious'] === true
            && collect($rows)->firstWhere('trooperId', $pl->id)['owesPrevious'] === false));

    // A past week marks nobody.
    $this->actingAs($this->tree['users']['co'])->get('/staff-reports?week=2026-10-03')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('owed', null)
        ->where('own.owesPrevious', false));
});
