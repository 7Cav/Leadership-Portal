<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\TestTelemetryConnection;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\TelemetryConnection;
use App\Models\TelemetryInterface;
use App\Repositories\GameRepository;
use App\Repositories\TelemetryConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Game server connections (#183): for every game, the interface that reads it and each source a capture would read,
 * whether that source's credentials are set (never the credentials or the address, AD-23), when it last answered and
 * when and why it last failed, as recorded by the capture runs and "Test connection". So an Admin can tell "the feed is
 * down" from "nobody played". Reads the repositories directly; the test is a Command.
 *
 * Admin-only, like the Servers page: viewing follows a view-as preview, and the test checks the real Admin rule,
 * `ScopeResolver::isAdmin()` (AD-6), before looking the game up.
 */
class ConnectionsController extends Controller
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request, CaptureSources $sources, TelemetryConnectionRepository $connections): Response
    {
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may see the game server connections.');
        }

        $recorded = $connections->allByGame();

        return Inertia::render('Settings/Connections', [
            'games' => $this->games->all()->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'retired' => $game->isRetired(),
                'interface' => $game->interface->value,
                'configured' => $sources->isConfigured($game),
                'servers' => array_map(fn (CaptureServer $server) => [
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
            'interfaces' => array_map(fn (TelemetryInterface $interface) => ['value' => $interface->value, 'label' => $interface->label()], TelemetryInterface::cases()),
            'testResult' => $request->session()->get('connection_test'),
        ]);
    }

    public function test(Request $request, int $game, TestTelemetryConnection $command): RedirectResponse
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may test the game server connections.');
        }

        $game = $this->games->find($game) ?? abort(404);

        return redirect()->route('settings.connections')->with('connection_test', ['gameId' => $game->id, 'outcomes' => $command->handle($game)]);
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
}
