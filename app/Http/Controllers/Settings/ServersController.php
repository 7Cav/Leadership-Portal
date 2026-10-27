<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\TelemetryCredentials;
use App\Commands\Servers\FetchGameServers;
use App\Commands\Servers\InvalidGameServerException;
use App\Commands\Servers\UpdateGameServer;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Repositories\GameRepository;
use App\Repositories\GameServerRepository;
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
 * the credentials themselves, AD-23) and the last capture that read it. Reads the repositories directly; the writes
 * are Commands, the Admin's choices audited (AD-16).
 *
 * The whole screen is Admin-only, like the audit log. Viewing follows a view-as preview (an Admin previewing a leader
 * sees what that leader would: a 403); the writes check the real `is_admin` flag (AD-6) before anything else, so a
 * non-Admin gets a 403 whether or not the server exists.
 */
class ServersController extends Controller
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly GameRepository $games,
        private readonly TelemetryCredentials $credentials,
    ) {}

    public function index(Request $request, ScopeResolver $scope, CaptureSources $sources): Response
    {
        if (! $scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may manage game servers.');
        }

        $lastReads = $this->servers->lastReads();
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
                'lastRead' => isset($lastReads[$server->id]) ? [
                    'succeeded' => $lastReads[$server->id]->succeeded,
                    'reason' => $lastReads[$server->id]->reason,
                    'at' => $lastReads[$server->id]->read_at->toIso8601String(),
                ] : null,
            ])->values()->all(),
            'games' => $this->games->all()->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'retired' => $game->isRetired(),
                'interface' => $game->interface->value,
                // With no server selected the game reads its interface's server from env, as before (#389).
                'readsEnvServer' => ! $this->servers->hasSelectedFor($game) && $game->interface !== TelemetryInterface::None,
                'captureConfigured' => $sources->isConfigured($game),
            ])->values()->all(),
            'interfaces' => array_values(array_map(fn (TelemetryInterface $interface) => [
                'value' => $interface->value,
                'label' => $interface->label(),
                'credentialsSet' => $this->credentials->areSet($interface),
            ], array_filter(TelemetryInterface::cases(), fn (TelemetryInterface $interface) => $interface !== TelemetryInterface::None))),
            'types' => array_map(fn (GameServerType $type) => ['value' => $type->value, 'label' => $type->label()], GameServerType::cases()),
            'fetchResult' => $request->session()->get('servers_fetch'),
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

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may manage game servers.');
        }
    }
}
