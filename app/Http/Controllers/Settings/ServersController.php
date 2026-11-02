<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\TelemetryCredentials;
use App\Commands\Capture\TestTelemetryConnection;
use App\Commands\Servers\FetchGameServers;
use App\Commands\Servers\InvalidGameServerException;
use App\Commands\Servers\UpdateGameServer;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryConnection;
use App\Models\TelemetryInterface;
use App\Repositories\GameRepository;
use App\Repositories\GameServerRepository;
use App\Repositories\TelemetryConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The game servers the telemetry APIs list (#389): an Admin fetches them, selects the ones the portal should know
 * about, assigns each a game and marks it training or public; captures read only the selected training servers.
 * Per game, the page shows each selected server, its interface, whether that interface's credentials are set (never
 * the credentials themselves or an address, AD-23) and, for each source a capture would read, when it last answered
 * and when and why it last failed (#183), as recorded by the capture runs and "Test connection", so an Admin can tell
 * "the feed is down" from "nobody played". Reads the repositories directly; the writes and the test are Commands, the
 * Admin's choices audited (AD-16); a test only reads, so it is not.
 *
 * The whole screen is Admin-only, like the audit log. Viewing follows a view-as preview (an Admin previewing a leader
 * sees what that leader would: a 403); the writes check the real Admin rule, `ScopeResolver::isAdmin()` (AD-6), before anything else, so a
 * non-Admin gets a 403 whether or not the server exists.
 */
class ServersController extends Controller
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly GameRepository $games,
        private readonly TelemetryCredentials $credentials,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request, ScopeResolver $scope, CaptureSources $sources, TelemetryConnectionRepository $connections): Response
    {
        if (! $scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may manage game servers.');
        }

        $recorded = $connections->allByGame();
        $servers = $this->servers->all();

        return Inertia::render('Settings/Servers', [
            'servers' => $servers->map(fn (GameServer $server) => [
                'id' => $server->id,
                'interface' => $server->interface->value,
                'externalId' => $server->external_id,
                'name' => $server->name,
                'gameId' => $server->game_id,
                'type' => $server->type->value,
                'selected' => $server->selected,
                'fallbackId' => $server->fallback_server_id,
                'missing' => $server->missing_since !== null,
                'credentialsSet' => $this->credentials->areSet($server->interface),
            ])->values()->all(),
            'games' => $this->games->all()->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'retired' => $game->isRetired(),
                'interface' => $game->interface->value,
                // With no server selected the game reads its interface's server from env, as before (#389), when that
                // server is its own: a game added or switched to another interface reads nothing until one is selected.
                'readsEnvServer' => $sources->readsEnvServer($game),
                'captureConfigured' => $sources->isConfigured($game),
                // Every server a capture would read for the game, with how each of its sources last went (#183).
                'connections' => array_map(fn (CaptureServer $server) => [
                    'label' => $server->label,
                    'sources' => array_map(function (CaptureSource $source) use ($recorded, $game) {
                        $interface = TelemetryInterface::from($source->name);
                        $row = $recorded[$game->id][TelemetryConnection::targetFor($source->gameServerId, $interface)] ?? null;

                        return [
                            'interface' => $interface->value,
                            'configured' => $source->configured,
                            ...self::outcome($row),
                        ];
                    }, $server->sources),
                ], $sources->for($game)),
            ])->values()->all(),
            'interfaces' => array_values(array_map(fn (TelemetryInterface $interface) => [
                'value' => $interface->value,
                'label' => $interface->label(),
                'credentialsSet' => $this->credentials->areSet($interface),
            ], array_filter(TelemetryInterface::cases(), fn (TelemetryInterface $interface) => $interface !== TelemetryInterface::None))),
            'types' => array_map(fn (GameServerType $type) => ['value' => $type->value, 'label' => $type->label()], GameServerType::cases()),
            'fetchResult' => $request->session()->get('servers_fetch'),
            'testResult' => $request->session()->get('connection_test'),
        ]);
    }

    public function fetch(Request $request, FetchGameServers $command): RedirectResponse
    {
        $this->authorizeAdmin($request);

        return redirect()->route('settings.servers')->with('servers_fetch', $command->handle());
    }

    public function update(Request $request, int $server, UpdateGameServer $command): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $server = $this->servers->find($server) ?? abort(404);

        $data = $request->validate([
            'selected' => ['required', 'boolean'],
            'game_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::enum(GameServerType::class)],
            'fallback_server_id' => ['nullable', 'integer', Rule::notIn([$server->id])],
        ]);

        $game = isset($data['game_id']) ? ($this->games->find($data['game_id']) ?? throw ValidationException::withMessages(['game_id' => 'That game no longer exists.'])) : null;
        $fallback = isset($data['fallback_server_id'])
            ? ($this->servers->find($data['fallback_server_id']) ?? throw ValidationException::withMessages(['fallback_server_id' => 'That server no longer exists.']))
            : null;

        try {
            $command->handle($request->user(), $server, (bool) $data['selected'], $game, GameServerType::from($data['type']), $fallback);
        } catch (InvalidGameServerException $e) {
            throw ValidationException::withMessages(['server' => $e->getMessage()]);
        }

        return redirect()->route('settings.servers');
    }

    /** Reads each source of the game once, read-only, and records the outcome like a capture run would (#183). */
    public function test(Request $request, int $game, TestTelemetryConnection $command): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $game = $this->games->find($game) ?? abort(404);

        return redirect()->route('settings.servers')->with('connection_test', ['gameId' => $game->id, 'outcomes' => $command->handle($game)]);
    }

    /** @return array{lastSucceededAt: string|null, lastFailedAt: string|null, lastFailureReason: string|null} */
    private static function outcome(?TelemetryConnection $row): array
    {
        return [
            'lastSucceededAt' => $row?->last_succeeded_at?->toIso8601String(),
            'lastFailedAt' => $row?->last_failed_at?->toIso8601String(),
            'lastFailureReason' => $row?->last_failed_at === null ? null : $row->last_failure_reason,
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may manage game servers.');
        }
    }
}
