<?php

use App\Commands\Attendance\MakeupMarkResult;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Loa\LoaCoverage;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\Loa;
use App\Models\LoaType;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// Decided 2026-10-23 (AD-14 refined): a trooper is on one rollcall, their primary section's. Having
// attended another section's practice, they made up their own: preMarkMakeups() writes `makeup`/`auto`
// on their own section's practice for the same Cav week, never downgrading, never over a leader, never
// into a locked week. The capture does not call it until practice time slots exist, so these tests call
// the writer directly. A makeup stands on a sighting (review L1), so each call first records the trooper at the
// host practice, as a host leader's hand entry does.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->host = Unit::factory()->create(['type' => UnitType::Section]);
    $this->home = Unit::factory()->create(['type' => UnitType::Section]);
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->hostPractice = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $this->week]);
    $this->visitor = Trooper::factory()->create(['primary_section_id' => $this->home->id, 'status' => TrooperStatus::Combat]);
});

/** @param  list<int>  $trooperIds */
function makeUp(SectionPractice $host, array $trooperIds, int|string $by = 'system', bool $sighted = true): MakeupMarkResult
{
    foreach ($sighted ? $trooperIds : [] as $trooperId) {
        CrossSectionSighting::query()->firstOrCreate(
            ['trooper_id' => $trooperId, 'section_practice_id' => $host->id],
            ['week_start' => $host->week_start, 'source' => CrossSectionSighting::SOURCE_MANUAL],
        );
    }

    return app(MarkAttendanceHandler::class)->preMarkMakeups($host, $trooperIds, $by);
}

function homePracticeOf(Unit $section, CarbonImmutable $week): ?SectionPractice
{
    return SectionPractice::query()->where('section_id', $section->id)->where('week_start', $week)->first();
}

function markOn(SectionPractice $practice, Trooper $trooper): ?Attendance
{
    return Attendance::query()->where('section_practice_id', $practice->id)->where('trooper_id', $trooper->id)->first();
}

it('pre-marks an auto makeup on the trooper\'s own rollcall that week', function () {
    $result = makeUp($this->hostPractice, [$this->visitor->id]);

    $home = homePracticeOf($this->home, $this->week);
    $row = markOn($home, $this->visitor);
    expect($result)->toEqual(new MakeupMarkResult(marked: 1))
        ->and($home)->not->toBeNull()
        ->and($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        // Nothing on the host practice: they are not on its rollcall.
        ->and(markOn($this->hostPractice, $this->visitor))->toBeNull();
});

it('writes no makeup with no sighting of the trooper that week, nor an empty practice row', function () {
    // A sighting of another week does not support this week's makeup either.
    $lastWeek = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $this->week->subWeek()]);
    CrossSectionSighting::query()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $lastWeek->id, 'week_start' => $lastWeek->week_start, 'source' => CrossSectionSighting::SOURCE_MANUAL]);

    $result = makeUp($this->hostPractice, [$this->visitor->id], sighted: false);

    expect($result)->toEqual(new MakeupMarkResult(kept: 1))
        ->and(homePracticeOf($this->home, $this->week))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0);
});

it('makes up only the troopers a sighting supports, in one call', function () {
    $unseen = Trooper::factory()->create(['primary_section_id' => $this->home->id, 'status' => TrooperStatus::Combat]);
    CrossSectionSighting::query()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $this->hostPractice->id, 'week_start' => $this->week, 'source' => CrossSectionSighting::SOURCE_MANUAL]);

    $result = makeUp($this->hostPractice, [$this->visitor->id, $unseen->id], sighted: false);

    $home = homePracticeOf($this->home, $this->week);
    expect($result)->toEqual(new MakeupMarkResult(marked: 1, kept: 1))
        ->and(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Makeup)
        ->and(markOn($home, $unseen))->toBeNull();
});

it('never downgrades: a present at their own practice stays present', function (AttendanceSource $source) {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => AttendanceStatus::Present, 'source' => $source]);

    $result = makeUp($this->hostPractice, [$this->visitor->id]);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe($source)
        ->and($result)->toEqual(new MakeupMarkResult(kept: 1));
})->with(['auto' => [AttendanceSource::Auto], 'manual' => [AttendanceSource::Manual]]);

it('turns an unmarked row or an auto missed into the makeup, keeping a rollcall signature', function (?AttendanceStatus $status, ?AttendanceSource $source) {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => $status, 'source' => $source, 'rollcall' => 'will_attend']);

    makeUp($this->hostPractice, [$this->visitor->id]);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        ->and($row->rollcall->value)->toBe('will_attend')
        ->and(Attendance::query()->where('section_practice_id', $home->id)->count())->toBe(1);
})->with([
    'rollcall-only' => [null, null],
    'auto missed' => [AttendanceStatus::Missed, AttendanceSource::Auto],
]);

it('leaves a leader\'s manual mark of any status untouched', function (AttendanceStatus $status) {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create([
        'trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => $status,
        'source' => AttendanceSource::Manual, 'updated_at' => '2026-10-05 12:00:00',
    ]);

    makeUp($this->hostPractice, [$this->visitor->id]);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe($status)
        ->and($row->source)->toBe(AttendanceSource::Manual)
        ->and($row->updated_at->toDateTimeString())->toBe('2026-10-05 12:00:00');
})->with([[AttendanceStatus::Missed], [AttendanceStatus::Makeup], [AttendanceStatus::Attached]]);

it('credits the host practice\'s own week only: a previous week leaves this week alone', function () {
    $current = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    $lastWeek = $this->week->subWeek();
    $hostLastWeek = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $lastWeek]);

    makeUp($hostLastWeek, [$this->visitor->id]);

    expect(markOn($current, $this->visitor))->toBeNull()
        ->and(markOn(homePracticeOf($this->home, $lastWeek), $this->visitor)->status)->toBe(AttendanceStatus::Makeup);
});

it('writes nothing into their own week once that week\'s report is sent', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);

    $result = makeUp($this->hostPractice, [$this->visitor->id]);

    expect($result)->toEqual(new MakeupMarkResult(locked: 1))
        ->and(homePracticeOf($this->home, $this->week))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0);
});

it('leaves their own practice alone once its leader confirmed it', function () {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    $home->forceFill(['confirmed_at' => now(), 'confirmed_by' => User::factory()->create()->id])->save();

    $result = makeUp($this->hostPractice, [$this->visitor->id]);

    expect(markOn($home, $this->visitor))->toBeNull()
        ->and($result)->toEqual(new MakeupMarkResult(locked: 1));
});

it('writes nothing into a week before the floor', function () {
    config(['portal.week_floor_weeks' => 0]);
    $lastWeek = $this->week->subWeek();
    $hostLastWeek = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $lastWeek]);

    makeUp($hostLastWeek, [$this->visitor->id]);

    expect(homePracticeOf($this->home, $lastWeek))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0);
});

it('does not pre-mark a trooper whose leave covers the week, nor create an empty practice row for them', function () {
    Loa::factory()->create(['trooper_id' => $this->visitor->id, 'type' => LoaType::Loa, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-12']);
    expect(app(LoaCoverage::class)->forTrooper($this->visitor->id, $this->week)->derivedAttendanceStatus())->not->toBeNull();

    makeUp($this->hostPractice, [$this->visitor->id]);

    expect(homePracticeOf($this->home, $this->week))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0);
});

it('does not pre-mark a trooper who no longer serves', function () {
    $this->visitor->forceFill(['status' => TrooperStatus::Inactive])->save();

    makeUp($this->hostPractice, [$this->visitor->id]);

    expect(homePracticeOf($this->home, $this->week))->toBeNull();
});

it('replaces the auto makeup with present when their own practice is captured later and sees them', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->visitor->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000077']);
    makeUp($this->hostPractice, [$this->visitor->id]);
    $home = homePracticeOf($this->home, $this->week);
    expect(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Makeup);

    $start = CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable();
    $end = CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable();
    $source = new CaptureSource('crcon', 'crcon-server-1', fn ($s, $e) => new CrconPresence([new CrconPlayer('V', '76561190000000077')], $s, $e, CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC')->toDateTimeImmutable()));
    app()->instance(CaptureSources::class, new CaptureSources(['crcon' => [$source]]));
    app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice($home->id, game('hll'), $start, $end, 'system'));

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe(AttendanceStatus::Present)->and($row->source)->toBe(AttendanceSource::Auto);

    // And a later makeup for the same week does not take it back down.
    makeUp($this->hostPractice, [$this->visitor->id]);
    expect(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Present);
});

it('audits a user-triggered makeup as one auto-mark batch on their own section\'s practice', function () {
    $user = User::factory()->create();

    makeUp($this->hostPractice, [$this->visitor->id], $user->id);

    $entry = AuditLogEntry::query()->where('action', 'attendance.auto_marked')->sole();
    expect((int) $entry->target_id)->toBe(homePracticeOf($this->home, $this->week)->id)
        ->and($entry->actor_id)->toBe($user->id);
});

it('makes up each trooper in their own section, several sections in one call', function () {
    $third = Unit::factory()->create(['type' => UnitType::Section]);
    $other = Trooper::factory()->create(['primary_section_id' => $third->id, 'status' => TrooperStatus::Combat]);

    makeUp($this->hostPractice, [$this->visitor->id, $other->id]);

    expect(markOn(homePracticeOf($this->home, $this->week), $this->visitor)->status)->toBe(AttendanceStatus::Makeup)
        ->and(markOn(homePracticeOf($third, $this->week), $other)->status)->toBe(AttendanceStatus::Makeup)
        ->and(Attendance::query()->where('section_practice_id', homePracticeOf($this->home, $this->week)->id)->count())->toBe(1);
});
