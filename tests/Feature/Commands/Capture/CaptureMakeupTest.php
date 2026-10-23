<?php

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
use App\Models\GamePlatform;
use App\Models\Loa;
use App\Models\LoaType;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// Decided 2026-10-23 (AD-14 refined): a trooper is on one rollcall, their primary section's. Seen at
// another section's practice, they made up their own: capture pre-marks `makeup`/`auto` on their own
// section's practice for the same Cav week, never downgrading, never over a leader, never into a
// locked week. Every such sighting is recorded once, whatever it did to the mark.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->host = Unit::factory()->create(['type' => UnitType::Section]);
    $this->home = Unit::factory()->create(['type' => UnitType::Section]);
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->hostPractice = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $this->week]);
    $this->visitor = Trooper::factory()->create(['primary_section_id' => $this->home->id, 'status' => TrooperStatus::Combat]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->visitor->id, 'platform' => GamePlatform::Hll, 'platform_id' => '76561190000000077']);
});

/** Capture `$practice` with a source that saw these platform ids, in a window on `$day` (Y-m-d). */
function captureSeeing(SectionPractice $practice, array $platformIds, string $day = '2026-10-08', int|string $by = 'system')
{
    $start = CarbonImmutable::parse("{$day} 18:00:00", 'UTC')->toDateTimeImmutable();
    $end = CarbonImmutable::parse("{$day} 19:30:00", 'UTC')->toDateTimeImmutable();
    $players = array_map(fn (string $id) => new CrconPlayer("P{$id}", $id), $platformIds);
    $source = new CaptureSource('crcon', 'crcon-server-1', fn ($s, $e) => new CrconPresence($players, $s, $e, CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC')->toDateTimeImmutable()));
    app()->instance(CaptureSources::class, new CaptureSources([GamePlatform::Hll->value => [$source]]));

    return app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice($practice->id, GamePlatform::Hll, $start, $end, $by));
}

function homePracticeOf(Unit $section, CarbonImmutable $week): ?SectionPractice
{
    return SectionPractice::query()->where('section_id', $section->id)->where('week_start', $week)->first();
}

function markOn(SectionPractice $practice, Trooper $trooper): ?Attendance
{
    return Attendance::query()->where('section_practice_id', $practice->id)->where('trooper_id', $trooper->id)->first();
}

it('pre-marks a trooper seen at another section\'s practice as an auto makeup on their own rollcall that week', function () {
    $result = captureSeeing($this->hostPractice, ['76561190000000077']);

    $home = homePracticeOf($this->home, $this->week);
    $row = markOn($home, $this->visitor);
    expect($result->otherSection)->toHaveCount(1)
        ->and($home)->not->toBeNull()
        ->and($row->status)->toBe(AttendanceStatus::Makeup)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        // Nothing on the host practice: they are not on its rollcall.
        ->and(markOn($this->hostPractice, $this->visitor))->toBeNull()
        ->and(SyncRun::query()->sole()->error)->toContain('(1 in another section)')
        ->and(SyncRun::query()->sole()->error)->toContain('makeup on their own rollcall 1, left 0 as they were (present/manual/loa), 0 in a locked week');
});

it('never downgrades: a present at their own practice stays present', function (AttendanceSource $source) {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => AttendanceStatus::Present, 'source' => $source]);

    captureSeeing($this->hostPractice, ['76561190000000077']);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe($source)
        ->and(SyncRun::query()->sole()->error)->toContain('makeup on their own rollcall 0, left 1 as they were');
})->with(['auto' => [AttendanceSource::Auto], 'manual' => [AttendanceSource::Manual]]);

it('turns an unmarked row or an auto missed into the makeup, keeping a rollcall signature', function (?AttendanceStatus $status, ?AttendanceSource $source) {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => $status, 'source' => $source, 'rollcall' => 'will_attend']);

    captureSeeing($this->hostPractice, ['76561190000000077']);

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

    captureSeeing($this->hostPractice, ['76561190000000077']);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe($status)
        ->and($row->source)->toBe(AttendanceSource::Manual)
        ->and($row->updated_at->toDateTimeString())->toBe('2026-10-05 12:00:00');
})->with([[AttendanceStatus::Missed], [AttendanceStatus::Makeup], [AttendanceStatus::Attached]]);

it('credits the sighting\'s own week only: a previous week\'s sighting leaves this week alone', function () {
    $current = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    $lastWeek = $this->week->subWeek();
    $hostLastWeek = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $lastWeek]);

    captureSeeing($hostLastWeek, ['76561190000000077'], '2026-10-01');

    expect(markOn($current, $this->visitor))->toBeNull()
        ->and(markOn(homePracticeOf($this->home, $lastWeek), $this->visitor)->status)->toBe(AttendanceStatus::Makeup)
        ->and(CrossSectionSighting::query()->sole()->week_start->toDateString())->toBe('2026-09-26');
});

it('writes nothing into their own week once that week\'s report is sent, and still records the sighting', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);

    $result = captureSeeing($this->hostPractice, ['76561190000000077']);

    expect($result->captured)->toBeTrue()
        ->and(homePracticeOf($this->home, $this->week))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(1)
        ->and(SyncRun::query()->sole()->error)->toContain('makeup on their own rollcall 0, left 0 as they were (present/manual/loa), 1 in a locked week');
});

it('leaves their own practice alone once its leader confirmed it', function () {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    $home->forceFill(['confirmed_at' => now(), 'confirmed_by' => User::factory()->create()->id])->save();

    captureSeeing($this->hostPractice, ['76561190000000077']);

    expect(markOn($home, $this->visitor))->toBeNull()
        ->and(SyncRun::query()->sole()->error)->toContain('1 in a locked week');
});

it('writes nothing into a week before the floor', function () {
    config(['portal.week_floor_weeks' => 0]);
    $lastWeek = $this->week->subWeek();
    $hostLastWeek = SectionPractice::factory()->create(['section_id' => $this->host->id, 'week_start' => $lastWeek]);

    captureSeeing($hostLastWeek, ['76561190000000077'], '2026-10-01');

    expect(homePracticeOf($this->home, $lastWeek))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0);
});

it('does not pre-mark a trooper whose leave covers the week, nor create an empty practice row for them', function () {
    Loa::factory()->create(['trooper_id' => $this->visitor->id, 'type' => LoaType::Loa, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-12']);
    expect(app(LoaCoverage::class)->forTrooper($this->visitor->id, $this->week)->derivedAttendanceStatus())->not->toBeNull();

    captureSeeing($this->hostPractice, ['76561190000000077']);

    expect(homePracticeOf($this->home, $this->week))->toBeNull()
        ->and(Attendance::query()->where('trooper_id', $this->visitor->id)->count())->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(1);
});

it('does not pre-mark a trooper who no longer serves', function () {
    $this->visitor->forceFill(['status' => TrooperStatus::Inactive])->save();

    captureSeeing($this->hostPractice, ['76561190000000077']);

    expect(homePracticeOf($this->home, $this->week))->toBeNull();
});

it('replaces the auto makeup with present when their own practice is captured later and sees them', function () {
    captureSeeing($this->hostPractice, ['76561190000000077']);
    $home = homePracticeOf($this->home, $this->week);
    expect(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Makeup);

    captureSeeing($home, ['76561190000000077']);

    $row = markOn($home, $this->visitor);
    expect($row->status)->toBe(AttendanceStatus::Present)->and($row->source)->toBe(AttendanceSource::Auto);

    // And a later re-run of the other section's capture does not take it back down.
    captureSeeing($this->hostPractice, ['76561190000000077']);
    expect(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Present);
});

it('records every sighting once, a re-run adding nothing, also when it changed no mark', function () {
    $home = SectionPractice::factory()->create(['section_id' => $this->home->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $this->visitor->id, 'section_practice_id' => $home->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Auto]);
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $unplaced->id, 'platform' => GamePlatform::Hll, 'platform_id' => '76561190000000078']);
    $mine = Trooper::factory()->create(['primary_section_id' => $this->host->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $mine->id, 'platform' => GamePlatform::Hll, 'platform_id' => '76561190000000079']);

    captureSeeing($this->hostPractice, ['76561190000000077', '76561190000000078', '76561190000000079', '76561190000000080']);
    captureSeeing($this->hostPractice, ['76561190000000077', '76561190000000078', '76561190000000079'], '2026-10-07');

    $sightings = CrossSectionSighting::query()->orderBy('trooper_id')->get();
    expect($sightings->pluck('trooper_id')->all())->toBe([$this->visitor->id, $unplaced->id])
        ->and($sightings->every(fn ($s) => $s->section_practice_id === $this->hostPractice->id))->toBeTrue()
        ->and($sightings[0]->week_start->toDateString())->toBe('2026-10-03')
        ->and($sightings[0]->source)->toBe('capture')
        // The first sighting wins: the re-run's window (10-07) did not overwrite it.
        ->and($sightings[0]->seen_from->toDateTimeString())->toBe('2026-10-08 18:00:00')
        ->and($sightings[0]->seen_until->toDateTimeString())->toBe('2026-10-08 19:30:00')
        ->and(markOn($home, $this->visitor)->status)->toBe(AttendanceStatus::Present);
});

it('audits a user-triggered makeup as one auto-mark batch on their own section\'s practice', function () {
    $user = User::factory()->create();

    captureSeeing($this->hostPractice, ['76561190000000077'], by: $user->id);

    // The host saw none of its own troopers, so its pre-mark audits nothing; the makeup is one entry.
    $entry = AuditLogEntry::query()->where('action', 'attendance.auto_marked')->sole();
    expect((int) $entry->target_id)->toBe(homePracticeOf($this->home, $this->week)->id)
        ->and($entry->actor_id)->toBe($user->id);
});

it('makes up each trooper in their own section, several sections from one capture', function () {
    $third = Unit::factory()->create(['type' => UnitType::Section]);
    $other = Trooper::factory()->create(['primary_section_id' => $third->id, 'status' => TrooperStatus::Combat]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'platform' => GamePlatform::Hll, 'platform_id' => '76561190000000081']);

    captureSeeing($this->hostPractice, ['76561190000000077', '76561190000000081']);

    expect(markOn(homePracticeOf($this->home, $this->week), $this->visitor)->status)->toBe(AttendanceStatus::Makeup)
        ->and(markOn(homePracticeOf($third, $this->week), $other)->status)->toBe(AttendanceStatus::Makeup)
        ->and(Attendance::query()->where('section_practice_id', homePracticeOf($this->home, $this->week)->id)->count())->toBe(1);
});
