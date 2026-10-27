<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\Attendance;
use App\Models\GameServer;
use App\Models\GameServerRead;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// #389: a capture reads every training server of the game. A player seen on any of them counts; one server that
// cannot be read leaves the practice un-captured rather than writing a smaller list. Each read is recorded.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
    $this->start = CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable();
    $this->end = CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable();
    $this->a = GameServer::factory()->trainingFor(game('hll')->id)->create(['name' => 'Training A']);
    $this->b = GameServer::factory()->trainingFor(game('hll')->id)->create(['name' => 'Training B']);
});

function serverAnswering(GameServer $server, string $name, array $players): CaptureSource
{
    return new CaptureSource($name, "{$name}:{$server->external_id}", fn ($start, $end) => new CrconPresence($players, $start, $end, CarbonImmutable::now()->toDateTimeImmutable()), true, $server->id);
}

function serverFailing(GameServer $server, string $name): CaptureSource
{
    return new CaptureSource($name, "{$name}:{$server->external_id}", fn () => throw new CaptureSourceUnavailable("{$name}: unreachable"), true, $server->id);
}

/** @param list<CaptureServer> $servers */
function captureServers(object $test, array $servers)
{
    app()->instance(CaptureSources::class, new CaptureSources([], fn () => $servers));

    return app(CaptureSectionPracticeHandler::class)->handle(
        new CaptureSectionPractice($test->practice->id, game('hll')->id, $test->start, $test->end, 'system'),
    );
}

function trooperOn(object $test, string $platformId): Trooper
{
    $trooper = Trooper::factory()->create(['primary_section_id' => $test->section->id]);
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game('hll')->id, 'platform_id' => $platformId]);

    return $trooper;
}

it('counts a player seen on any training server, once, and names every server that answered', function () {
    $one = trooperOn($this, '1');
    $two = trooperOn($this, '2');

    $result = captureServers($this, [
        new CaptureServer($this->a->id, 'Training A', [serverAnswering($this->a, 'crcon', [new CrconPlayer('One', '1'), new CrconPlayer('Two', '2')])]),
        new CaptureServer($this->b->id, 'Training B', [serverAnswering($this->b, 'battlemetrics', [new CrconPlayer('Two again', '2'), new CrconPlayer('Stranger', '3')])]),
    ]);

    $practice = $this->practice->fresh();

    expect($result->captured)->toBeTrue()
        ->and($result->seen())->toBe(3)
        ->and(array_column($result->matched, 'trooperId'))->toEqualCanonicalizing([$one->id, $two->id])
        ->and($result->unmatchedCount())->toBe(1)
        ->and($practice->server_ref)->toBe("crcon:{$this->a->external_id}, battlemetrics:{$this->b->external_id}")
        ->and($practice->capture_source)->toBe('crcon,battlemetrics')
        ->and(Attendance::query()->count())->toBe(2)
        ->and(GameServerRead::query()->where('succeeded', true)->pluck('game_server_id')->all())->toEqualCanonicalizing([$this->a->id, $this->b->id]);
});

it('leaves the practice un-captured when one training server cannot be read, though the other answered', function () {
    trooperOn($this, '1');

    $result = captureServers($this, [
        new CaptureServer($this->a->id, 'Training A', [serverAnswering($this->a, 'battlemetrics', [new CrconPlayer('One', '1')])]),
        new CaptureServer($this->b->id, 'Training B', [serverFailing($this->b, 'battlemetrics')]),
    ]);

    $run = SyncRun::query()->sole();

    expect($result->captured)->toBeFalse()
        ->and($result->unavailableReasons)->toBe(['Training B: battlemetrics: unreachable'])
        ->and($this->practice->fresh()->captured_at)->toBeNull()
        ->and(Attendance::query()->count())->toBe(0)
        ->and($run->status)->toBe(SyncRunStatus::Failure)
        ->and($run->error)->toContain('telemetry unavailable')->toContain('Training B: battlemetrics: unreachable')
        ->and(GameServerRead::query()->where('game_server_id', $this->a->id)->sole()->succeeded)->toBeTrue()
        ->and(GameServerRead::query()->where('game_server_id', $this->b->id)->sole())
        ->succeeded->toBeFalse()
        ->reason->toBe('battlemetrics: unreachable')
        ->sync_run_id->toBe($run->id);
});

it('reads a CRCON server\'s BattleMetrics twin when CRCON is down, recording each read against its own server', function () {
    $twin = GameServer::factory()->create(['selected' => true]);
    trooperOn($this, '1');

    $result = captureServers($this, [
        new CaptureServer($this->a->id, 'Training A', [serverFailing($this->a, 'crcon'), serverAnswering($twin, 'battlemetrics', [new CrconPlayer('One', '1')])]),
    ]);

    expect($result->captured)->toBeTrue()
        ->and($this->practice->fresh()->capture_source)->toBe('battlemetrics')
        ->and(GameServerRead::query()->where('game_server_id', $this->a->id)->sole()->succeeded)->toBeFalse()
        ->and(GameServerRead::query()->where('game_server_id', $twin->id)->sole()->succeeded)->toBeTrue();
});

it('counts a training server without credentials as unreadable while another one has them', function () {
    $result = captureServers($this, [
        new CaptureServer($this->a->id, 'Training A', [serverAnswering($this->a, 'battlemetrics', [])]),
        new CaptureServer($this->b->id, 'Training B', [new CaptureSource('nightwatch', 'nightwatch:x', fn () => throw new LogicException('never called'), false, $this->b->id)]),
    ]);

    expect($result->captured)->toBeFalse()
        ->and($result->unavailableReasons)->toBe(['Training B: credentials not set'])
        ->and(GameServerRead::query()->where('game_server_id', $this->b->id)->sole()->reason)->toBe('credentials not set');
});

it('attempts nothing and records no run when no training server has credentials', function () {
    $result = captureServers($this, [
        new CaptureServer($this->a->id, 'Training A', [new CaptureSource('battlemetrics', 'battlemetrics:x', fn () => throw new LogicException('never called'), false, $this->a->id)]),
    ]);

    expect($result->attempted)->toBeFalse()
        ->and(SyncRun::query()->count())->toBe(0)
        ->and(GameServerRead::query()->count())->toBe(0);
});
