<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
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
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id]);
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
        ->and($run->error)->toBe('source crcon: seen 2, matched 1 (0 in another section), unmatched 1');
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
