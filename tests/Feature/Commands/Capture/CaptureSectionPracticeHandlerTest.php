<?php

use App\Commands\Attendance\MarkAttendanceHandler;
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
use App\Models\CrossSectionSighting;
use App\Models\Game;
use App\Models\GamePlayerDismissal;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PracticeCapturePlayerRepository;
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

function runCapture(object $test, array $sources, int|string $by = 'system', ?Game $game = null)
{
    $game ??= game('hll');
    app()->instance(CaptureSources::class, new CaptureSources([$game->interface->value => $sources]));

    return app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($test->practice->id, $game->id, $test->start, $test->end, $by),
    );
}

function linkIdentity(Trooper $trooper, string $platformId, ?Game $game = null): void
{
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => ($game ?? game('hll'))->id, 'platform_id' => $platformId]);
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
    linkIdentity($mine, 'shared-id', game('arma'));

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('X', 'shared-id')])]);

    expect($result->matched)->toBe([])->and($result->unmatched)->toHaveCount(1);
});

it('surfaces a linked trooper from another section instead of crediting them to this practice', function () {
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
        ->and($result->unmatched)->toBe([])
        ->and(Attendance::query()->where('section_practice_id', $this->practice->id)->count())->toBe(0);
});

it('matches a linked trooper placed on an HQ node, but marks them nowhere', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $psg = Trooper::factory()->create(['primary_section_id' => $platoon->id]);
    linkIdentity($psg, '76561190000000020');

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('Hale', '76561190000000020')])]);

    expect($result->matched)->toBe([])
        ->and($result->otherSection)->toHaveCount(1)
        ->and($result->unmatched)->toBe([])
        ->and(Attendance::query()->count())->toBe(0);
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
    $result = runCapture($this, [], game: game('arma'));

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
        ->and($run->error)->toBe('practice '.$this->practice->id.', source crcon: seen 2, matched 1 (0 in another section), unmatched 1; pre-marked 1, left 0 untouched (manual/loa)');
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
        ->and(SyncRun::query()->sole()->internal_error)->toBeTrue()
        ->and($this->practice->fresh()->capture_source)->toBeNull();
});

it('does not mark a run internal when a source could not answer', function () {
    runCapture($this, [failingSource('crcon')]);

    expect(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failure)
        ->and(SyncRun::query()->sole()->internal_error)->toBeFalse();
});

it('closes a run of this practice left in progress by a killed worker, and not one for another practice', function () {
    $orphan = SyncRun::query()->forceCreate(['source' => 'capture', 'started_at' => now()->subHour(), 'status' => SyncRunStatus::InProgress, 'triggered_by' => 'system', 'section_practice_id' => $this->practice->id]);
    $running = SyncRun::query()->forceCreate(['source' => 'capture', 'started_at' => now()->subMinute(), 'status' => SyncRunStatus::InProgress, 'triggered_by' => 'system', 'section_practice_id' => SectionPractice::factory()->create()->id]);

    runCapture($this, [answeringSource('crcon', [])]);

    expect($orphan->fresh()->status)->toBe(SyncRunStatus::Failure)
        ->and($orphan->fresh()->finished_at)->not->toBeNull()
        ->and($running->fresh()->status)->toBe(SyncRunStatus::InProgress);
});

// A name past the column once failed the whole run on MySQL, and the raw SQL error (with the
// player data in it) became the run's stored error.
it('stores a player name longer than its column cut to fit, keyed on the platform id', function () {
    $long = str_repeat('Ä', 300);

    runCapture($this, [answeringSource('crcon', [new CrconPlayer($long, '76561198000000001')])]);

    $stored = PracticeCapturePlayer::query()->sole();
    expect($stored->player_name)->toBe(str_repeat('Ä', 255))
        ->and($stored->platform_id)->toBe('76561198000000001')
        ->and(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Partial);
});

it('records a plain sentence, not the exception text, when the run fails unexpectedly', function () {
    $source = new CaptureSource('crcon', 'x', fn () => throw new RuntimeException("SQLSTATE[22001]: Data too long for column 'player_name' (insert ... 'Smith')"));

    expect(fn () => runCapture($this, [$source]))->toThrow(RuntimeException::class);

    expect(SyncRun::query()->sole()->error)->toBe("the capture hit an unexpected error (practice {$this->practice->id}, RuntimeException)");
});

it('refuses a practice that does not exist', function () {
    app()->instance(CaptureSources::class, new CaptureSources([]));

    expect(fn () => app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice(999999, game('hll')->id, $this->start, $this->end),
    ))->toThrow(InvalidArgumentException::class)
        ->and(SyncRun::query()->count())->toBe(0);
});

it('reads the game\'s interface when the run starts, not when it was queued (#388)', function () {
    app()->instance(CaptureSources::class, new CaptureSources(['crcon' => [answeringSource('crcon', [])], 'nightwatch' => [answeringSource('nightwatch', [])]]));
    $queued = new CaptureSectionPractice($this->practice->id, game('hll')->id, $this->start, $this->end);
    game('hll')->forceFill(['interface' => 'nightwatch'])->save();

    app(CaptureSectionPracticeHandler::class)->handle($queued);

    expect($this->practice->fresh()->capture_source)->toBe('nightwatch');
});

it('refuses a game that no longer exists, so the job fails instead of retrying', function () {
    app()->instance(CaptureSources::class, new CaptureSources([]));

    expect(fn () => app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($this->practice->id, 999999, $this->start, $this->end),
    ))->toThrow(InvalidArgumentException::class, 'Game 999999 does not exist.')
        ->and(SyncRun::query()->count())->toBe(0);
});

it('refuses a window outside the practice week', function () {
    app()->instance(CaptureSources::class, new CaptureSources([]));
    $outside = CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC')->toDateTimeImmutable();

    expect(fn () => app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($this->practice->id, game('hll')->id, $outside, $outside),
    ))->toThrow(InvalidArgumentException::class);
});

// 7.6 / AD-14, AD-22: matched troopers are pre-marked present/auto; manual wins; never loa.

it('pre-marks a matched trooper present with source auto and nobody else on this practice', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $elsewhere = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id]);
    linkIdentity($mine, '1');
    linkIdentity($elsewhere, '2');

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1'), new CrconPlayer('B', '2'), new CrconPlayer('C', '3')])]);

    $row = Attendance::query()->where('section_practice_id', $this->practice->id)->sole();
    expect($row->trooper_id)->toBe($mine->id)
        ->and($row->status)->toBe(AttendanceStatus::Present)
        ->and($row->source)->toBe(AttendanceSource::Auto)
        // Nor anywhere else: the capture writes no makeup for another section's trooper (2026-10-23).
        ->and(Attendance::query()->where('trooper_id', $elsewhere->id)->count())->toBe(0);
});

// 2026-10-23: until practice time slots exist the capture window is the whole Cav week on servers every
// section shares, so another section's trooper seen there is listed for review and credited nowhere.

it('writes no sighting and leaves the own rollcall of another section\'s trooper unchanged, still listing them for review', function () {
    $home = Unit::factory()->create(['type' => UnitType::Section]);
    $visitor = Trooper::factory()->create(['primary_section_id' => $home->id, 'status' => TrooperStatus::Combat]);
    $away = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id, 'status' => TrooperStatus::Combat]);
    linkIdentity($visitor, '76561190000000020');
    linkIdentity($away, '76561190000000021');
    $homePractice = SectionPractice::factory()->create(['section_id' => $home->id, 'week_start' => '2026-10-03 00:00:00']);
    Attendance::factory()->create(['trooper_id' => $visitor->id, 'section_practice_id' => $homePractice->id, 'status' => null, 'source' => null, 'rollcall' => 'will_attend']);
    $before = Attendance::query()->sole()->only(['status', 'source', 'rollcall', 'updated_at']);

    $result = runCapture($this, [answeringSource('crcon', [
        new CrconPlayer('Visitor', '76561190000000020'),
        new CrconPlayer('Away', '76561190000000021'),
    ])], User::factory()->create()->id);

    expect($result->otherSection)->toHaveCount(2)
        ->and(CrossSectionSighting::query()->count())->toBe(0)
        // Their own rollcall is as it was, and no practice row appears for a section that had none.
        ->and(Attendance::query()->sole()->only(['status', 'source', 'rollcall', 'updated_at']))->toEqual($before)
        ->and(Attendance::query()->where('trooper_id', $away->id)->count())->toBe(0)
        ->and(SectionPractice::query()->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->count())->toBe(0)
        ->and(SyncRun::query()->sole()->error)->toContain('(2 in another section)')
        ->and(SyncRun::query()->sole()->error)->not->toContain('makeup')
        // Still on this practice's review list as other-section players.
        ->and(PracticeCapturePlayer::query()->where('section_practice_id', $this->practice->id)->orderBy('trooper_id')->pluck('trooper_id')->all())
        ->toBe(collect([$visitor->id, $away->id])->sort()->values()->all());
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
    expect(SyncRun::query()->sole()->error)->toContain('pre-marked 0, left 3 untouched (manual/loa)');
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

it('does not stamp the practice and records the run failed when the pre-mark throws', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');
    $this->mock(MarkAttendanceHandler::class)->shouldReceive('preMark')->andThrow(new RuntimeException('db down'));

    expect(fn () => runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1')])]))->toThrow(RuntimeException::class);

    expect($this->practice->fresh()->capture_source)->toBeNull()
        ->and(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Failure);
});

// 7.7: the unmatched / other-section players are kept for the leader's review.

it('stores the unmatched and other-section players of the practice, replaced on every run', function () {
    $elsewhere = Trooper::factory()->create(['primary_section_id' => Unit::factory()->create(['type' => UnitType::Section])->id]);
    linkIdentity($elsewhere, '76561190000000010');
    $players = [new CrconPlayer('Stranger', '76561190000000002'), new CrconPlayer('Visitor', '76561190000000010')];

    runCapture($this, [answeringSource('crcon', $players)]);
    runCapture($this, [answeringSource('crcon', $players)]);

    $stored = PracticeCapturePlayer::query()->where('section_practice_id', $this->practice->id)->orderBy('platform_id')->get();
    expect($stored)->toHaveCount(2)
        ->and($stored[0]->only(['player_name', 'platform_id', 'trooper_id']))->toBe(['player_name' => 'Stranger', 'platform_id' => '76561190000000002', 'trooper_id' => null])
        ->and($stored[0]->game_id)->toBe(game('hll')->id)
        ->and($stored[1]->trooper_id)->toBe($elsewhere->id);

    runCapture($this, [answeringSource('crcon', [])]);
    expect(PracticeCapturePlayer::query()->count())->toBe(0);
});

it('stores a player a leader already dismissed, but does not list them, so the dismissal can be undone here (#176)', function () {
    GamePlayerDismissal::query()->create(['game_id' => game('hll')->id, 'platform_id' => '76561190000000002', 'player_name' => 'Rando', 'dismissed_by' => User::factory()->create()->id]);

    runCapture($this, [answeringSource('crcon', [new CrconPlayer('Rando', '76561190000000002'), new CrconPlayer('Other', '76561190000000003')])]);

    $players = app(PracticeCapturePlayerRepository::class);
    expect(PracticeCapturePlayer::query()->orderBy('platform_id')->pluck('platform_id')->all())->toBe(['76561190000000002', '76561190000000003'])
        ->and($players->unmatchedFor($this->practice->id)->pluck('platform_id')->all())->toBe(['76561190000000003'])
        ->and($players->dismissedFor($this->practice->id)->pluck('platform_id')->all())->toBe(['76561190000000002']);
});

it('keeps the earlier stored players when a later re-run finds every source down', function () {
    runCapture($this, [answeringSource('crcon', [new CrconPlayer('Stranger', '76561190000000002')])]);
    runCapture($this, [failingSource('crcon')]);

    expect(PracticeCapturePlayer::query()->count())->toBe(1);
});

// 7.9 / AD-23, AD-20, AD-22: telemetry unavailable falls back to manual, and recovery keeps manual marks.

function unconfiguredSource(string $name, array &$calls): CaptureSource
{
    return new CaptureSource($name, "{$name}-server-1", function () use (&$calls, $name) {
        $calls[] = $name;
        throw new CaptureSourceUnavailable("{$name}: should not be called");
    }, configured: false);
}

it('attempts nothing and records no run when no source has its keys set', function () {
    $calls = [];
    $result = runCapture($this, [unconfiguredSource('crcon', $calls), unconfiguredSource('battlemetrics', $calls)]);

    $practice = $this->practice->fresh();
    expect($result->captured)->toBeFalse()
        ->and($result->attempted)->toBeFalse()
        ->and($calls)->toBe([])
        ->and(SyncRun::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0)
        ->and($practice->capture_source)->toBeNull()
        ->and($practice->captured_at)->toBeNull();
});

it('skips an unconfigured source without calling it and reads the configured fallback', function () {
    $calls = [];
    $result = runCapture($this, [unconfiguredSource('crcon', $calls), answeringSource('battlemetrics', [])]);

    expect($result->captured)->toBeTrue()
        ->and($calls)->toBe([])
        ->and($this->practice->fresh()->capture_source)->toBe('battlemetrics')
        ->and(SyncRun::query()->sole()->status)->toBe(SyncRunStatus::Success);
});

it('is not telemetry down when the primary source fails but the fallback answers', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '1');

    $result = runCapture($this, [failingSource('crcon'), answeringSource('battlemetrics', [new CrconPlayer('A', '1')])]);

    $run = SyncRun::query()->sole();
    expect($result->captured)->toBeTrue()
        ->and($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->section_practice_id)->toBe($this->practice->id)
        ->and(Attendance::query()->sole()->source)->toBe(AttendanceSource::Auto);
});

it('names the practice on a failed run, writes no auto rows and leaves capture_source null', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    Attendance::factory()->create([
        'trooper_id' => $mine->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual,
    ]);

    runCapture($this, [failingSource('crcon')]);

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->section_practice_id)->toBe($this->practice->id)
        ->and($this->practice->fresh()->capture_source)->toBeNull()
        ->and(Attendance::query()->where('source', AttendanceSource::Auto->value)->count())->toBe(0)
        ->and(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Missed);
});

it('applies auto pre-marks on recovery and keeps the manual marks entered while it was down', function () {
    $manual = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $seen = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($manual, '1');
    linkIdentity($seen, '2');

    runCapture($this, [failingSource('crcon')]);
    expect(Attendance::query()->count())->toBe(0);

    // The leader marks by hand while the server is down; then it comes back.
    Attendance::factory()->create([
        'trooper_id' => $manual->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual,
    ]);

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('A', '1'), new CrconPlayer('B', '2')])]);

    $byTrooper = Attendance::query()->get()->keyBy('trooper_id');
    expect($result->captured)->toBeTrue()
        ->and($byTrooper[$manual->id]->status)->toBe(AttendanceStatus::Missed)
        ->and($byTrooper[$manual->id]->source)->toBe(AttendanceSource::Manual)
        ->and($byTrooper[$seen->id]->status)->toBe(AttendanceStatus::Present)
        ->and($byTrooper[$seen->id]->source)->toBe(AttendanceSource::Auto)
        ->and($this->practice->fresh()->capture_source)->toBe('crcon');
});

it('records a failed run naming only the configured source when the unconfigured primary is skipped', function () {
    $calls = [];
    $result = runCapture($this, [unconfiguredSource('crcon', $calls), failingSource('battlemetrics')]);

    $run = SyncRun::query()->sole();
    expect($result->captured)->toBeFalse()
        ->and($calls)->toBe([])
        ->and($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->error)->toContain('battlemetrics: unreachable')->not->toContain('crcon');
});

// AD-12: a sent weekly report freezes the week's attendance; an automatic capture may not change it.

it('captures nothing for a week whose report has been sent, and records a week-locked run', function (int|string $by) {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '76561190000000001');
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->practice->week_start]);
    $asked = null;

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('Ranger', '76561190000000001')], $asked)], is_string($by) ? $by : User::factory()->create()->id);

    expect($result->weekLocked)->toBeTrue()
        ->and($result->captured)->toBeFalse()
        ->and($result->seen())->toBe(0)
        ->and($asked)->toBeNull()
        ->and(Attendance::query()->count())->toBe(0)
        ->and($this->practice->fresh()->capture_source)->toBeNull()
        ->and(AuditLogEntry::query()->count())->toBe(0);

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->error)->toContain('week locked')->toContain('2026-10-03');
})->with(['a system run' => ['system'], 'a user-triggered run' => [0]]);

it('writes nothing when the report is sent while the capture is reading its source', function () {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '76561190000000001');
    $source = new CaptureSource('crcon', 'crcon-server-1', function ($start, $end) {
        WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->practice->week_start]);

        return new CrconPresence([new CrconPlayer('Ranger', '76561190000000001')], $start, $end, CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC')->toDateTimeImmutable());
    });

    $result = runCapture($this, [$source]);

    expect($result->weekLocked)->toBeTrue()
        ->and(Attendance::query()->count())->toBe(0)
        ->and($this->practice->fresh()->capture_source)->toBeNull()
        ->and(SyncRun::query()->sole()->error)->toContain('week locked');
});

it('still captures and pre-marks a week whose report is a demo, a draft, or absent', function (Closure $report) {
    $mine = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    linkIdentity($mine, '76561190000000001');
    $report($this->section, $this->practice->week_start);

    $result = runCapture($this, [answeringSource('crcon', [new CrconPlayer('Ranger', '76561190000000001')])]);

    expect($result->captured)->toBeTrue()
        ->and($result->weekLocked)->toBeFalse()
        ->and(Attendance::query()->sole()->source)->toBe(AttendanceSource::Auto);
})->with([
    'a demo sent report (#115)' => [fn (Unit $section, $week) => WeeklyReport::factory()->sent()->create(['unit_id' => $section->id, 'week_start' => $week, 'is_demo' => true])],
    'a draft' => [fn (Unit $section, $week) => WeeklyReport::factory()->create(['unit_id' => $section->id, 'week_start' => $week])],
    'no report' => [fn () => null],
]);
