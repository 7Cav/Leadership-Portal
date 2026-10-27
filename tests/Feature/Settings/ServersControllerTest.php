<?php

use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\TelemetryCredentials;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Nightwatch\NightwatchApi;
use App\Models\AuditLogEntry;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\GameServerRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// #389: an Admin fetches the game servers the telemetry APIs list, selects them, assigns each a game and marks it
// training or public. Credentials stay in env (AD-23); the page says only whether they are set.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    config()->set('services.crcon', ['base_url' => 'https://crcon.test', 'api_key' => 'k']);
    config()->set('services.battlemetrics', ['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => null]);
    config()->set('services.nightwatch', ['base_url' => 'https://nw.test/api/public/stats', 'api_key' => null, 'server_ids' => null]);
    // The seams, the credentials and the source map are singletons read from config: rebuild them with the above.
    foreach ([CrconApi::class, BattleMetricsApi::class, NightwatchApi::class,
        TelemetryCredentials::class, CaptureSources::class] as $singleton) {
        app()->forgetInstance($singleton);
    }
});

function serverUpdate(array $override = []): array
{
    return ['selected' => true, 'game_id' => game('arma')->id, 'type' => 'training', 'fallback_server_id' => null, ...$override];
}

it('shows each game\'s selected servers with their type, interface, credentials and last read, and every fetched server', function () {
    $training = GameServer::factory()->trainingFor(game('arma')->id)->create(['name' => 'Arma Training', 'external_id' => '11']);
    GameServer::factory()->create(['game_id' => game('arma')->id, 'selected' => true, 'type' => GameServerType::Public, 'name' => 'Arma Public']);
    $wardogs = GameServer::factory()->trainingFor(game('wardogs')->id)->create(['interface' => TelemetryInterface::Nightwatch, 'missing_since' => now()]);
    $run = app(SyncRunRepository::class)->open('capture', 'system');
    app(GameServerRepository::class)->recordRead($training->id, $run->id, false, 'battlemetrics: unreachable');

    $this->actingAs($this->admin)->get('/settings/servers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Servers')
        ->has('servers', 3)
        ->where('servers', fn ($servers) => collect($servers)->firstWhere('id', $training->id)['lastRead']['succeeded'] === false
            && collect($servers)->firstWhere('id', $training->id)['lastRead']['reason'] === 'battlemetrics: unreachable'
            && collect($servers)->firstWhere('id', $training->id)['credentialsSet'] === true
            && collect($servers)->firstWhere('id', $wardogs->id)['credentialsSet'] === false
            && collect($servers)->firstWhere('id', $wardogs->id)['missing'] === true)
        ->where('interfaces', [
            ['value' => 'crcon', 'label' => 'CRCON', 'credentialsSet' => true],
            ['value' => 'battlemetrics', 'label' => 'BattleMetrics', 'credentialsSet' => true],
            ['value' => 'nightwatch', 'label' => 'Nightwatch', 'credentialsSet' => false],
        ])
        // HLL has no server selected: it reads its env server, which here has CRCON credentials.
        ->where('games.0', ['id' => game('hll')->id, 'name' => 'Hell Let Loose', 'retired' => false, 'interface' => 'crcon', 'readsEnvServer' => true, 'captureConfigured' => true])
        ->where('games.1.readsEnvServer', false)
        ->where('games.1.captureConfigured', true)
        // WARDOGS' only training server sits behind Nightwatch, whose key is unset.
        ->where('games.2.captureConfigured', false)
        ->where('fetchResult', null)
    );
});

it('never sends a credential to the page', function () {
    GameServer::factory()->create();

    $this->actingAs($this->admin)->get('/settings/servers')->assertOk()->assertDontSee('https://crcon.test')->assertDontSee('"k"', false);
});

it('fetches every interface with credentials, stores what each listed, and reports the ones it could not ask', function () {
    $gone = GameServer::factory()->trainingFor(game('arma')->id)->create(['external_id' => '99']);
    MockClient::global([
        'crcon.test/api/get_public_info' => MockResponse::make(['result' => ['name' => '7Cav HLL'], 'failed' => false, 'error' => null]),
        'bm.test/servers*' => MockResponse::make(['data' => [['type' => 'server', 'id' => '11', 'attributes' => ['name' => 'Arma Training']]], 'links' => []]),
    ]);

    $this->actingAs($this->admin)->post('/settings/servers/fetch')->assertRedirect('/settings/servers')
        ->assertSessionHas('servers_fetch', [
            ['interface' => 'crcon', 'status' => 'listed', 'listed' => 1, 'added' => 1, 'missing' => 0],
            ['interface' => 'battlemetrics', 'status' => 'listed', 'listed' => 1, 'added' => 1, 'missing' => 1],
            ['interface' => 'nightwatch', 'status' => 'not_configured'],
        ]);

    expect(GameServer::query()->where('interface', 'crcon')->sole()->name)->toBe('7Cav HLL')
        ->and(GameServer::query()->where('external_id', '11')->sole()->selected)->toBeFalse()
        ->and($gone->fresh()->missing_since)->not->toBeNull()
        ->and($gone->fresh()->selected)->toBeTrue();
});

it('reports an interface that cannot answer and leaves its stored servers as they are', function () {
    $kept = GameServer::factory()->trainingFor(game('arma')->id)->create(['external_id' => '11']);
    MockClient::global([
        'crcon.test/*' => MockResponse::make(['failed' => true, 'error' => 'boom']),
        'bm.test/servers*' => MockResponse::make([], 503),
    ]);

    $this->actingAs($this->admin)->post('/settings/servers/fetch')
        ->assertSessionHas('servers_fetch', fn (array $outcomes) => $outcomes[0]['status'] === 'failed'
            && $outcomes[1]['status'] === 'failed'
            && str_contains($outcomes[1]['message'], 'HTTP 503'));

    expect($kept->fresh()->missing_since)->toBeNull()
        ->and(GameServer::query()->count())->toBe(1);
});

it('selects a server, assigns its game and type, audited once; saving it unchanged records nothing more', function () {
    $server = GameServer::factory()->create();

    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", serverUpdate())->assertRedirect('/settings/servers')->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", serverUpdate());

    expect($server->fresh())
        ->selected->toBeTrue()
        ->game_id->toBe(game('arma')->id)
        ->type->toBe(GameServerType::Training)
        ->and(AuditLogEntry::query()->sole())
        ->action->toBe('game_server.updated')
        ->target_type->toBe('GameServer');
});

it('gives a CRCON server a selected BattleMetrics twin as its fallback', function () {
    $crcon = GameServer::factory()->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon']);
    $twin = GameServer::factory()->create(['selected' => true]);

    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id, 'fallback_server_id' => $twin->id]))
        ->assertSessionHasNoErrors();

    expect($crcon->fresh()->fallback_server_id)->toBe($twin->id);
});

it('refuses choices that do not fit together, writing nothing', function (Closure $setup, string $field, string $message) {
    [$server, $body] = $setup();

    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", $body)->assertSessionHasErrors([$field => $message]);

    expect(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'selected without a game' => [fn () => [GameServer::factory()->create(), serverUpdate(['game_id' => null])], 'server', 'Pick the game this server is for before selecting it.'],
    'a retired game' => [function () {
        game('arma')->update(['retired_at' => now()]);

        return [GameServer::factory()->create(), serverUpdate()];
    }, 'server', 'Arma Reforger is retired, so no server can be newly assigned to it.'],
    'a fallback on a non-CRCON server' => [fn () => [GameServer::factory()->create(), serverUpdate(['fallback_server_id' => GameServer::factory()->create(['selected' => true])->id])], 'server', 'Only a CRCON server can have a BattleMetrics fallback.'],
    'an unselected fallback' => [fn () => [GameServer::factory()->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon']), serverUpdate(['fallback_server_id' => GameServer::factory()->create()->id])], 'server', 'The fallback must be a selected BattleMetrics server.'],
    'a game that is gone' => [fn () => [GameServer::factory()->create(), serverUpdate(['game_id' => 999999])], 'game_id', 'That game no longer exists.'],
    'an unknown type' => [fn () => [GameServer::factory()->create(), serverUpdate(['type' => 'private'])], 'type', 'The selected type is invalid.'],
]);

it('deselects a server, audited, and it is no longer read', function () {
    $server = GameServer::factory()->trainingFor(game('arma')->id)->create();

    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", serverUpdate(['selected' => false]))->assertSessionHasNoErrors();

    expect($server->fresh()->selected)->toBeFalse()
        ->and(app(GameServerRepository::class)->trainingServersFor(game('arma')))->toBeEmpty()
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game_server.updated');
});

it('clears a CRCON server\'s fallback', function () {
    $twin = GameServer::factory()->create(['selected' => true, 'game_id' => game('hll')->id]);
    $crcon = GameServer::factory()->trainingFor(game('hll')->id)->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon', 'fallback_server_id' => $twin->id]);

    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id]))->assertSessionHasNoErrors();

    expect($crcon->fresh()->fallback_server_id)->toBeNull();
});

it('refuses to deselect a server that is still a fallback, until it is released there', function () {
    $twin = GameServer::factory()->create(['selected' => true, 'game_id' => game('hll')->id, 'name' => 'HLL on BM']);
    $crcon = GameServer::factory()->trainingFor(game('hll')->id)->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon', 'name' => 'HLL CRCON', 'fallback_server_id' => $twin->id]);

    $this->actingAs($this->admin)->patch("/settings/servers/{$twin->id}", serverUpdate(['selected' => false, 'game_id' => game('hll')->id]))
        ->assertSessionHasErrors(['server' => 'HLL on BM is the fallback of HLL CRCON. Remove it there before deselecting it.']);

    expect($twin->fresh()->selected)->toBeTrue();

    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id]));
    $this->actingAs($this->admin)->patch("/settings/servers/{$twin->id}", serverUpdate(['selected' => false, 'game_id' => game('hll')->id]))->assertSessionHasNoErrors();

    expect($twin->fresh()->selected)->toBeFalse();
});

it('refuses a server as its own fallback, a fallback that is gone, and one that is not BattleMetrics', function () {
    $crcon = GameServer::factory()->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon']);
    $nightwatch = GameServer::factory()->create(['interface' => TelemetryInterface::Nightwatch, 'selected' => true, 'game_id' => game('wardogs')->id]);

    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id, 'fallback_server_id' => $crcon->id]))->assertSessionHasErrors('fallback_server_id');
    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id, 'fallback_server_id' => 999999]))
        ->assertSessionHasErrors(['fallback_server_id' => 'That server no longer exists.']);
    $this->actingAs($this->admin)->patch("/settings/servers/{$crcon->id}", serverUpdate(['game_id' => game('hll')->id, 'fallback_server_id' => $nightwatch->id]))
        ->assertSessionHasErrors(['server' => 'The fallback must be a selected BattleMetrics server.']);

    expect($crcon->fresh()->fallback_server_id)->toBeNull();
});

it('leaves the stored servers of an interface without credentials untouched', function () {
    $stored = GameServer::factory()->trainingFor(game('wardogs')->id)->create(['interface' => TelemetryInterface::Nightwatch]);
    MockClient::global([
        'crcon.test/api/get_public_info' => MockResponse::make(['result' => ['name' => '7Cav HLL'], 'failed' => false, 'error' => null]),
        'bm.test/servers*' => MockResponse::make(['data' => [['type' => 'server', 'id' => '11', 'attributes' => ['name' => 'x']]], 'links' => []]),
    ]);

    $this->actingAs($this->admin)->post('/settings/servers/fetch');

    expect($stored->fresh()->missing_since)->toBeNull();
});

it('reports an empty or shapeless listing while servers are stored, flagging none of them missing', function (mixed $body, string $status) {
    config()->set('services.nightwatch', ['base_url' => 'https://nw.test/api/public/stats', 'api_key' => 'k', 'server_ids' => null]);
    app()->forgetInstance(NightwatchApi::class);
    app()->forgetInstance(TelemetryCredentials::class);
    $stored = GameServer::factory()->trainingFor(game('wardogs')->id)->create(['interface' => TelemetryInterface::Nightwatch]);
    MockClient::global([
        'crcon.test/*' => MockResponse::make(['result' => ['name' => '7Cav HLL'], 'failed' => false, 'error' => null]),
        'bm.test/*' => MockResponse::make(['data' => [], 'links' => []]),
        'nw.test/*' => MockResponse::make($body),
    ]);

    $this->actingAs($this->admin)->post('/settings/servers/fetch')
        ->assertSessionHas('servers_fetch', fn (array $outcomes) => $outcomes[2]['interface'] === 'nightwatch' && $outcomes[2]['status'] === $status);

    expect($stored->fresh()->missing_since)->toBeNull();
})->with([
    'servers: [] (an empty listing)' => [['servers' => []], 'empty'],
    'an empty object (no listing at all)' => [[], 'failed'],
]);

it('keeps a server on a game retired since it was assigned', function () {
    $server = GameServer::factory()->trainingFor(game('arma')->id)->create();
    game('arma')->update(['retired_at' => now()]);

    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", serverUpdate(['type' => 'public']))->assertSessionHasNoErrors();

    expect($server->fresh()->type)->toBe(GameServerType::Public);
});

it('answers a non-Admin with 403 on the page and every write, changing nothing', function () {
    $server = GameServer::factory()->create();
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get('/settings/servers')->assertForbidden();
    $this->actingAs($user)->post('/settings/servers/fetch')->assertForbidden();
    $this->actingAs($user)->patch("/settings/servers/{$server->id}", serverUpdate())->assertForbidden();
    $this->actingAs($user)->patch('/settings/servers/999999', serverUpdate())->assertForbidden();

    expect($server->fresh()->selected)->toBeFalse()->and(AuditLogEntry::query()->count())->toBe(0);
});

it('hides the page from an Admin previewing a unit, and refuses writes during the preview', function () {
    $company = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $server = GameServer::factory()->create();

    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $company->id])->assertSessionHasNoErrors();

    $this->get('/settings/servers')->assertForbidden();
    $this->patch("/settings/servers/{$server->id}", serverUpdate())->assertForbidden();
});

it('answers a server that does not exist with 404', function () {
    $this->actingAs($this->admin)->patch('/settings/servers/999999', serverUpdate())->assertNotFound();
});

it('links a server change in the audit log to the Servers page', function () {
    $server = GameServer::factory()->create(['name' => 'Arma Training']);
    $this->actingAs($this->admin)->patch("/settings/servers/{$server->id}", serverUpdate());

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.link', ['href' => '/settings/servers', 'label' => 'Arma Training server'])
    );
});
