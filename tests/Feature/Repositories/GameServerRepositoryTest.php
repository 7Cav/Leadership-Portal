<?php

use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;
use App\Repositories\SyncRunRepository;
use Illuminate\Support\Carbon;

// #389: the servers the telemetry APIs list, an Admin's choices about them, and each capture's read of one.

function servers(): GameServerRepository
{
    return app(GameServerRepository::class);
}

it('adds a newly listed server unselected and public, and renames a known one', function () {
    $known = GameServer::factory()->create(['external_id' => '42', 'name' => 'Old name', 'selected' => true, 'game_id' => game('arma')->id]);

    $result = servers()->storeListing(TelemetryInterface::BattleMetrics, [
        ['external_id' => '42', 'name' => 'New name'],
        ['external_id' => '43', 'name' => '7Cav Public'],
    ]);

    $added = GameServer::query()->where('external_id', '43')->sole();

    expect($result)->toBe(['added' => 1, 'missing' => 0])
        ->and($known->fresh()->name)->toBe('New name')
        ->and($known->fresh()->selected)->toBeTrue()
        ->and($known->fresh()->game_id)->toBe(game('arma')->id)
        ->and($added->selected)->toBeFalse()
        ->and($added->type)->toBe(GameServerType::Public)
        ->and($added->game_id)->toBeNull();
});

it('flags a stored server the listing left out as missing, keeps it selected, and clears the flag when it is listed again', function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $gone = GameServer::factory()->trainingFor(game('arma')->id)->create(['external_id' => '42']);
    $other = GameServer::factory()->create(['interface' => TelemetryInterface::Nightwatch, 'external_id' => '42']);

    expect(servers()->storeListing(TelemetryInterface::BattleMetrics, []))->toBe(['added' => 0, 'missing' => 1]);

    expect($gone->fresh()->missing_since?->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and($gone->fresh()->selected)->toBeTrue()
        ->and($other->fresh()->missing_since)->toBeNull();

    // Already flagged: the first time it went missing is kept.
    Carbon::setTestNow('2026-10-28 12:00:00');
    expect(servers()->storeListing(TelemetryInterface::BattleMetrics, [])['missing'])->toBe(0)
        ->and($gone->fresh()->missing_since?->toDateTimeString())->toBe('2026-10-27 12:00:00');

    servers()->storeListing(TelemetryInterface::BattleMetrics, [['external_id' => '42', 'name' => 'Back']]);
    expect($gone->fresh()->missing_since)->toBeNull();
});

it('returns only the selected training servers of the game', function () {
    $read = GameServer::factory()->trainingFor(game('arma')->id)->create();
    GameServer::factory()->create(['game_id' => game('arma')->id, 'type' => GameServerType::Public, 'selected' => true]);
    GameServer::factory()->create(['game_id' => game('arma')->id, 'type' => GameServerType::Training, 'selected' => false]);
    GameServer::factory()->trainingFor(game('hll')->id)->create();

    expect(servers()->trainingServersFor(game('arma'))->pluck('id')->all())->toBe([$read->id])
        ->and(servers()->hasSelectedFor(game('arma')))->toBeTrue()
        ->and(servers()->hasSelectedFor(game('wardogs')))->toBeFalse();
});

it('counts a game with only a selected public server as having a selected server', function () {
    GameServer::factory()->create(['game_id' => game('arma')->id, 'type' => GameServerType::Public, 'selected' => true]);

    expect(servers()->hasSelectedFor(game('arma')))->toBeTrue()
        ->and(servers()->trainingServersFor(game('arma')))->toBeEmpty();
});

it('records reads and returns the latest one per server', function () {
    $a = GameServer::factory()->create();
    $b = GameServer::factory()->create();
    $run = app(SyncRunRepository::class)->open('capture', 'system');

    servers()->recordRead($a->id, $run->id, false, 'battlemetrics: unreachable');
    servers()->recordRead($a->id, $run->id, true, null);
    servers()->recordRead($b->id, null, false, str_repeat('x', 400));

    $last = servers()->lastReads();

    expect(array_keys($last))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($last[$a->id]->succeeded)->toBeTrue()
        ->and($last[$b->id]->succeeded)->toBeFalse()
        ->and(mb_strlen($last[$b->id]->reason))->toBeLessThanOrEqual(253);
});

it('names the interfaces of the servers a run failed to read, each once', function () {
    $crcon = GameServer::factory()->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon']);
    $bm = GameServer::factory()->create();
    $other = GameServer::factory()->create(['interface' => TelemetryInterface::Nightwatch]);
    $run = app(SyncRunRepository::class)->open('capture', 'system');
    $earlier = app(SyncRunRepository::class)->open('capture', 'system');

    servers()->recordRead($crcon->id, $run->id, false, 'crcon: unreachable');
    servers()->recordRead($bm->id, $run->id, true, null);
    servers()->recordRead($crcon->id, $run->id, false, 'crcon: unreachable');
    servers()->recordRead($other->id, $earlier->id, false, 'nightwatch: unreachable');

    expect(servers()->failedInterfacesOf($run->id))->toBe(['crcon'])
        ->and(servers()->failedInterfacesOf(999999))->toBe([]);
});

it('updates the Admin\'s choices', function () {
    $crcon = GameServer::factory()->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon']);
    $twin = GameServer::factory()->create();

    $updated = servers()->update($crcon, true, game('hll')->id, GameServerType::Training, $twin->id);

    expect($updated->selected)->toBeTrue()
        ->and($updated->game->slug)->toBe('hll')
        ->and($updated->type)->toBe(GameServerType::Training)
        ->and($updated->fallback->id)->toBe($twin->id);
});
