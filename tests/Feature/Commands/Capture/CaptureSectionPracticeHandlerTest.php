<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\GamePlatform;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// 7.5 / AD-19, AD-14, AD-16: stamp the practice, resolve players, never auto-assign, never read an outage as zero.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
    $this->start = CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable();
    $this->end = CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable();
});

/** A source that answers with these players (and records the window it was asked for). */
function answeringSource(string $name, array $players, ?array &$asked = null): CaptureSource
{
    return new CaptureSource($name, "{$name}-server-1", function ($start, $end) use ($players, &$asked) {
        $asked = [$start, $end];

        return new CrconPresence($players, $start, $end, CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC')->toDateTimeImmutable());
    });
}

function failingSource(string $name): CaptureSource
{
    return new CaptureSource($name, "{$name}-server-1", fn () => throw new CaptureSourceUnavailable("{$name}: unreachable"));
}

function runCapture(object $test, array $sources, int|string $by = 'system', GamePlatform $platform = GamePlatform::Hll)
{
    app()->instance(CaptureSources::class, new CaptureSources([$platform->value => $sources]));

    return app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($test->practice->id, $platform, $test->start, $test->end, $by),
    );
}

function linkIdentity(Trooper $trooper, string $platformId, GamePlatform $platform = GamePlatform::Hll): void
{
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'platform' => $platform, 'platform_id' => $platformId]);
}

it('stamps the practice and asks the source only for the practice window', function () {
    $asked = null;
    $result = runCapture($this, [answeringSource('crcon', [], $asked)]);

    $practice = $this->practice->fresh();
    expect($result->captured)->toBeTrue()
        ->and($practice->capture_source)->toBe('crcon')
        ->and($practice->server_ref)->toBe('crcon-server-1')
        ->and($practice->captured_at->toIso8601String())->toBe('2026-10-08T20:00:00+00:00')
        ->and($asked)->toEqual([$this->start, $this->end]);
});

it('resolves linked players, surfaces unmatched ones and never assigns them', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '76561190000000001');

    $result = runCapture($this, [answeringSource('crcon', [
        new CrconPlayer('Ranger', '76561190000000001'),
        new CrconPlayer('Stranger', '76561190000000002'),
    ])]);

    expect($result->matched)->toHaveCount(1)
        ->and($result->matched[0]['trooperId'])->toBe($mine->id)
        ->and($result->unmatched)->toHaveCount(1)
        ->and($result->unmatched[0]->platformId)->toBe('76561190000000002')
        ->and($result->seen())->toBe(2)
        ->and($result->matchedCount())->toBe(1)
        ->and($result->unmatchedCount())->toBe(1)
        ->and(TrooperGameIdentity::query()->count())->toBe(1);
});

it('matches on platform as well as id', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, 'shared-id', GamePlatform::Arma);

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('X', 'shared-id')])]);

    expect($result->matched)->toBe([])->and($result->unmatched)->toHaveCount(1);
});

it('surfaces a linked trooper from another section instead of crediting them', function () {
    $elsewhere = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id]);
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);
    linkIdentity($elsewhere, '76561190000000010');
    linkIdentity($unplaced, '76561190000000011');

    $result = runCapture($this, [answeringSource('crcon', [
        new CrconPlayer('A', '76561190000000010'),
        new CrconPlayer('B', '76561190000000011'),
    ])]);

    expect($result->matched)->toBe([])
        ->and($result->otherSection)->toHaveCount(2)
        ->and($result->matchedCount())->toBe(2)
        ->and($result->unmatched)->toBe([]);
});

it('treats an answered-but-empty window as captured with zero players', function () {
    $result = runCapture($this, [answeringSource('crcon', [])]);

    expect($result->captured)->toBeTrue()->and($result->seen())->toBe(0)
        ->and($this->practice->fresh()->capture_source)->toBe('crcon');
});

it('falls back to the next source when the first is unavailable, stamping the one that answered', function () {
    $result = runCapture($this, [failingSource('crcon'), answeringSource('battlemetrics', [])]);

    expect($result->source)->toBe('battlemetrics')
        ->and($this->practice->fresh()->capture_source)->toBe('battlemetrics')
        ->and($this->practice->fresh()->server_ref)->toBe('battlemetrics-server-1');
});

it('leaves the practice un-captured and records telemetry unavailable when every source fails', function () {
    $result = runCapture($this, [failingSource('crcon'), failingSource('battlemetrics')]);

    $practice = $this->practice->fresh();
    expect($result->captured)->toBeFalse()
        ->and($result->seen())->toBe(0)
        ->and($practice->capture_source)->toBeNull()
        ->and($practice->server_ref)->toBeNull()
        ->and($practice->captured_at)->toBeNull();

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->error)->toContain('telemetry unavailable')->toContain('crcon: unreachable')->toContain('battlemetrics: unreachable');
});

it('keeps an earlier stamp when a later re-run finds every source down', function () {
    runCapture($this, [answeringSource('crcon', [])]);
    runCapture($this, [failingSource('crcon')]);

    expect($this->practice->fresh()->capture_source)->toBe('crcon');
});

it('is unavailable, not empty, when no source is configured for the game', function () {
    $result = runCapture($this, [], platform: GamePlatform::Arma);

    expect($result->captured)->toBeFalse()->and($this->practice->fresh()->capture_source)->toBeNull();
});

it('is idempotent: a re-run re-stamps the same practice row and re-resolves', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $players = [new CrconPlayer('Ranger', '76561190000000001')];

    $first = runCapture($this, [answeringSource('crcon', $players)]);
    linkIdentity($mine, '76561190000000001');
    $second = runCapture($this, [answeringSource('crcon', $players)]);

    expect($first->unmatched)->toHaveCount(1)
        ->and($second->matched)->toHaveCount(1)->and($second->unmatched)->toBe([])
        ->and(SectionPractice::query()->count())->toBe(1)
        ->and($this->practice->fresh()->capture_source)->toBe('crcon');
});

it('records the counts and who triggered the run', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1'), new CrconPlayer('B', '2')])]);

    $run = SyncRun::query()->sole();
    expect($run->source)->toBe('capture')
        ->and($run->triggered_by)->toBe('system')
        ->and($run->inserted)->toBe(1)
        ->and($run->errored)->toBe(1)
        ->and($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->error)->toBe('practice '.$this->practice->id.', source crcon: seen 2, matched 1 (0 in another section), unmatched 1; pre-marked 1, kept 0 manual');
});

it('audits the stamp when a user triggered the run, and not for the system', function () {
    $user = User::factory()->create();

    runCapture($this, [answeringSource('crcon', [])]);
    expect(AuditLogEntry::query()->count())->toBe(0);

    runCapture($this, [answeringSource('crcon', [])], $user->id);
    $entry = AuditLogEntry::query()->sole();
    expect($entry->action)->toBe('practice.captured')
        ->and($entry->actor_id)->toBe($user->id)
        ->and((int) $entry->target_id)->toBe($this->practice->id)
        ->and(SyncRun::query()->latest('id')->first()->triggered_by)->toBe((string) $user->id);
});

it('closes the run failed and rethrows on an unexpected error, leaving the practice untouched', function () {
    $source = new CaptureSource('crcon', 'x', fn () => throw new LogicException('boom'));

    expect(fn () => runCapture($this, [$source]))->toThrow(LogicException::class);

    expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failure)
        ->and($this->practice->fresh()->capture_source)->toBeNull();
});

it('refuses a practice that does not exist', function () {
    app()->instance(CaptureSources::class, new CaptureSources([]));

    expect(fn () => app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice(999999, GamePlatform::Hll, $this->start, $this->end),
    ))->toThrow(InvalidArgumentException::class)
        ->and(SyncRun::query()->count())->toBe(0);
});

it('refuses a window outside the practice week', function () {
    app()->instance(CaptureSources::class, new CaptureSources([]));
    $outside = CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC')->toDateTimeImmutable();

    expect(fn () => app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($this->practice->id, GamePlatform::Hll, $outside, $outside),
    ))->toThrow(InvalidArgumentException::class);
});

// 7.6 / AD-14, AD-22: matched troopers are pre-marked present/auto; manual wins; never loa.

it('pre-marks a matched trooper present with source auto and nobody else', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $elsewhere = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id]);
    linkIdentity($mine, '1');
    linkIdentity($elsewhere, '2');

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1'), new CrconPlayer('B', '2'), new CrconPlayer('C', '3')])]);

    $row = Attendance::query()->sole();
    expect($row->trooper_id)->toBe($mine->id)
        ->and($row->section_practice_id)->toBe($this->practice->id)
        ->and($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe(AttendanceSource::Auto);
});

it('leaves a manual mark of any status untouched, including its timestamps', function () {
    $troopers = Trooper::factory()->count(3)->create(['primary_section_id' => $this->section->id]);
    foreach ($troopers as $i => $trooper) {
        linkIdentity($trooper, (string) $i);
    }
    $statuses = [AttendanceStatus::Missed, AttendanceStatus::Attached, AttendanceStatus::Makeup];
    foreach ($troopers as $i => $trooper) {
        Attendance::factory()->create([
            'trooper_id' => $trooper->id, 'section_practice_id' => $this->practice->id,
            'status' => $statuses[$i], 'source' => AttendanceSource::Manual,
            'updated_at' => '2026-10-05 12:00:00',
        ]);
    }

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '0'), new CrconPlayer('B', '1'), new CrconPlayer('C', '2')])]);

    $rows = Attendance::query()->orderBy('trooper_id')->get();
    expect($rows)->toHaveCount(3);
    foreach ($rows as $i => $row) {
        expect($row->status)->toBe($statuses[$i])
            ->and($row->source)->toBe(AttendanceSource::Manual)
            ->and($row->updated_at->toDateTimeString())->toBe('2026-10-05 12:00:00');
    }
    expect(SyncRun::query()->sole()->error)->toContain('pre-marked 0, kept 3 manual');
});

it('updates a prior auto row in place on a re-run instead of duplicating it', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');
    Attendance::factory()->create([
        'trooper_id' => $mine->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Auto,
    ]);
    $players = [new CrconPlayer('A', '1')];

    runCapture($this, [answeringSource('crcon', $players)]);
    runCapture($this, [answeringSource('crcon', $players)]);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Present)->and($row->source)->toBe(AttendanceSource::Auto);
});

it('keeps a rollcall signature when it pre-marks the row', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');
    Attendance::factory()->create([
        'trooper_id' => $mine->id, 'section_practice_id' => $this->practice->id,
        'status' => null, 'source' => null, 'rollcall' => 'will_attend',
    ]);

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1')])]);

    $row = Attendance::query()->sole();
    expect($row->status)->toBe(AttendanceStatus::Present)->and($row->rollcall->value)->toBe('will_attend');
});

it('never overwrites an loa row and never writes loa itself', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');
    Attendance::factory()->create([
        'trooper_id' => $mine->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Loa, 'source' => AttendanceSource::Auto,
    ]);

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1')])]);

    expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Loa)
        ->and(Attendance::query()->where('status', AttendanceStatus::Loa->value)->count())->toBe(1);
});

it('writes no marks when no source answers', function () {
    runCapture($this, [failingSource('crcon')]);

    expect(Attendance::query()->count())->toBe(0);
});

it('audits the pre-mark as one batch when a user triggered the run', function () {
    $user = User::factory()->create();
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1')])], $user->id);

    expect(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->sole()->actor_id)->toBe($user->id);
});
