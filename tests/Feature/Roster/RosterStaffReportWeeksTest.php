<?php

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\PlacementSource;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;

// Line HQ staff on the roster: a week they were on a platoon, company or battalion HQ and on no section's roll call
// reads their staff report. "Now" is Thursday 2026-10-08, so the running Cav week starts Saturday 2026-10-03 and is
// the last of the 20 history weeks (index 19).

function hqWeek(int $back = 0): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')->subWeeks($back);
}

function hqStaffIn(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

function hqReport(Trooper $trooper, Unit $hq, int $back, bool $attended, array $sections = [], array $fields = []): StaffReport
{
    return StaffReport::query()->create([
        'trooper_id' => $trooper->id,
        'unit_id' => $hq->id,
        'week_start' => hqWeek($back),
        'attended' => $attended,
        'practice_type' => $attended ? 'sp' : null,
        'practices' => array_map(fn (Unit $s) => ['section_id' => $s->id, 'section' => $s->callsign], $sections),
        'presence_type' => 'public_server',
        'sent_at' => hqWeek($back)->addDays(6),
        ...$fields,
    ]);
}

/** The roster row of `$trooper` on `$unit`'s roster, as `$user` sees it. */
function hqRow(User $user, Unit $unit, Trooper $trooper): array
{
    return collect(test()->actingAs($user)->get(route('roster.index', $unit->id))->viewData('page')['props']['troopers'])
        ->firstWhere('id', $trooper->id);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->admin = User::factory()->admin()->create();
    // Staff reports from long before the history window, unless a test says otherwise.
    config(['portal.staff_reports_start' => '2026-01-03']);
});

it('reads a company HQ staff member\'s weeks from their staff reports: met, short, not sent and open', function () {
    $xo = hqStaffIn($this->company, ['tis' => '2020-01-01']);
    hqReport($xo, $this->company, 1, true, [$this->section]);
    hqReport($xo, $this->company, 2, false);

    $row = hqRow($this->admin, $this->company, $xo);

    expect($row['staffWeeks'])->toHaveCount(20)
        ->and($row['staffWeeks'][19])->toMatchArray(['state' => 'open', 'hq' => 'A/1-7', 'sent' => false, 'report' => null])
        ->and($row['staffWeeks'][18])->toMatchArray(['state' => 'met', 'sent' => true])
        ->and($row['staffWeeks'][18]['report'])->toMatchArray(['attended' => true, 'practiceType' => 'sp', 'presenceType' => 'public_server', 'practices' => ['1/1/A/1-7']])
        ->and($row['staffWeeks'][17]['state'])->toBe('short')
        ->and($row['staffWeeks'][16]['state'])->toBe('notSent')
        ->and($row['staffWeeks'][0]['state'])->toBe('notSent')
        // The four finished weeks before the running one, as RC and SP 30d span four.
        ->and($row['staffReports'])->toBe(['met' => 1, 'due' => 4, 'weeks' => 4]);
});

it('shows the weeks before staff reports could be filed as none yet, and leaves them out of the summary', function () {
    // 12SEP26 is the first week a report could be filed for; reading the start as a Saturday, a Monday works too.
    config(['portal.staff_reports_start' => '2026-09-14']);
    $xo = hqStaffIn($this->company, ['tis' => '2020-01-01']);
    hqReport($xo, $this->company, 1, true, [$this->section]);

    $row = hqRow($this->admin, $this->company, $xo);

    expect($row['staffWeeks'][15]['state'])->toBe('before')
        ->and($row['staffWeeks'][0]['state'])->toBe('before')
        ->and($row['staffWeeks'][16]['state'])->toBe('notSent')
        ->and($row['staffWeeks'][18]['state'])->toBe('met')
        ->and($row['staffReports'])->toBe(['met' => 1, 'due' => 3, 'weeks' => 4]);
});

it('judges a named type and a sent report the same way the staff report pages do', function () {
    $pl = hqStaffIn($this->platoon, ['tis' => '2020-01-01']);
    // An FTX with no section picked keeps the rule; a Yes with nothing picked does not.
    hqReport($pl, $this->platoon, 1, true, [], ['practice_type' => 'ftx', 'ftx_name' => 'Anvil', 'ftx_date' => '2026-09-30']);
    hqReport($pl, $this->platoon, 2, true, []);

    $weeks = hqRow($this->admin, $this->platoon, $pl)['staffWeeks'];

    expect($weeks[18]['state'])->toBe('met')
        ->and($weeks[18]['report']['ftx'])->toBe(['name' => 'Anvil', 'date' => '2026-09-30'])
        ->and($weeks[17]['state'])->toBe('short');
});

it('shows a week on leave as LOA and leaves it out of the reports summary', function () {
    $xo = hqStaffIn($this->company, ['tis' => '2020-01-01']);
    Loa::factory()->create(['trooper_id' => $xo->id, 'starts_at' => '2026-09-28', 'ends_at' => '2026-10-01']);
    hqReport($xo, $this->company, 2, true, [$this->section]);

    $row = hqRow($this->admin, $this->company, $xo);

    expect($row['staffWeeks'][18]['state'])->toBe('loa')
        ->and($row['staffReports'])->toBe(['met' => 1, 'due' => 3, 'weeks' => 4]);
});

it('reads the staff report for a week they were only a staff guest at a section\'s practice', function () {
    $psg = hqStaffIn($this->platoon, ['tis' => '2020-01-01']);
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, hqWeek(1));
    app(AttendanceRepository::class)->markPractice($practice, $psg->id, AttendanceStatus::Present, AttendanceSource::Manual);

    $row = hqRow($this->admin, $this->platoon, $psg);

    // A staff guest is not on the host's roll call, so the week is still theirs to report.
    expect($row['weeks'][18])->toBeNull()
        ->and($row['staffWeeks'][18]['state'])->toBe('notSent');
});

it('sends nothing for section troopers, regimental staff and groups', function () {
    $units = app(UnitRepository::class);
    $eloa = $units->create(UnitType::Group, 'ELOA', 'ELOA', $this->regiment->id, UnitKind::Eloa);
    $trooper = hqStaffIn($this->section, ['tis' => '2020-01-01']);
    $regimental = hqStaffIn($this->regiment, ['tis' => '2020-01-01']);
    $away = hqStaffIn($eloa, ['tis' => '2020-01-01']);

    expect(hqRow($this->admin, $this->section, $trooper))->toMatchArray(['staffWeeks' => null, 'staffReports' => null])
        ->and(hqRow($this->admin, $this->regiment, $regimental))->toMatchArray(['staffWeeks' => null, 'staffReports' => null])
        ->and(hqRow($this->admin, $eloa, $away))->toMatchArray(['staffWeeks' => null, 'staffReports' => null]);
});

it('leaves out the weeks before they joined', function () {
    $xo = hqStaffIn($this->company, ['tis' => '2026-09-21']);

    $row = hqRow($this->admin, $this->company, $xo);

    // The week of 19SEP26 holds their join date; the one before it does not.
    expect($row['staffWeeks'][17]['state'])->toBe('notSent')
        ->and($row['staffWeeks'][16])->toBeNull()
        ->and($row['staffReports'])->toBe(['met' => 0, 'due' => 2, 'weeks' => 4]);
});

it('reads only the weeks on the HQ for someone who moved there from a section', function () {
    $placements = app(TrooperPlacementRepository::class);
    $moved = hqStaffIn($this->section, ['tis' => '2020-01-01']);
    $placements->record([$moved->id => [null, $this->section->id]], PlacementSource::Sync, CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'));
    // Moved to the platoon HQ on Monday 21SEP26. The week of 19SEP26 still puts them in the section, as its roll call
    // reads it, so the HQ weeks start the week after.
    $placements->record([$moved->id => [$this->section->id, $this->platoon->id]], PlacementSource::Sync, CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));
    $moved->forceFill(['primary_section_id' => $this->platoon->id])->save();

    $row = hqRow($this->admin, $this->platoon, $moved);

    expect($row['staffWeeks'][18])->toMatchArray(['state' => 'notSent', 'hq' => '1/A/1-7'])
        ->and($row['staffWeeks'][17])->toBeNull()
        ->and($row['inSection'][17])->toBeTrue()
        ->and($row['inSection'][18])->toBeFalse();
});

it('shows a peer the week\'s state but not what the report said', function () {
    // The company commander reads the platoon HQ staff's reports, not their own HQ peers' (StaffReportAccess::mayRead).
    UnitCommandMapping::factory()->create(['unit_id' => $this->company->id, 'cav_position_id' => 601]);
    $co = hqStaffIn($this->company, ['cav_position_id' => 601, 'tis' => '2020-01-01']);
    $user = User::factory()->serving()->create(['cav_id' => $co->cav_id]);
    $xo = hqStaffIn($this->company, ['tis' => '2020-01-01']);
    $pl = hqStaffIn($this->platoon, ['tis' => '2020-01-01']);
    hqReport($xo, $this->company, 1, true, [$this->section]);
    hqReport($pl, $this->platoon, 1, true, [$this->section]);

    $peer = hqRow($user, $this->company, $xo)['staffWeeks'][18];
    $below = hqRow($user, $this->company, $pl)['staffWeeks'][18];
    $own = hqRow($user, $this->company, $co)['staffWeeks'][18];

    expect($peer)->toMatchArray(['state' => 'met', 'sent' => true, 'report' => null])
        ->and($below['report']['practices'])->toBe(['1/1/A/1-7'])
        ->and($own)->toMatchArray(['state' => 'notSent', 'report' => null]);
});

it('sends the staff weeks and the reports summary on the Sheet too', function () {
    $xo = hqStaffIn($this->company, ['tis' => '2020-01-01']);
    hqReport($xo, $this->company, 1, true, [$this->section]);

    $this->actingAs($this->admin)->getJson("/roster/troopers/{$xo->id}")
        ->assertOk()
        ->assertJsonPath('trooper.staffWeeks.18.state', 'met')
        ->assertJsonPath('trooper.staffWeeks.18.report.practices', ['1/1/A/1-7'])
        ->assertJsonPath('trooper.staffReports', ['met' => 1, 'due' => 4, 'weeks' => 4]);
});
