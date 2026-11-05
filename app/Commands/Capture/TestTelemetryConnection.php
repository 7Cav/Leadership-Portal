<?php

namespace App\Commands\Capture;

use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Repositories\TelemetryConnectionRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * "Test connection" on the Game server connections page (#183): read each source a capture of the game reads, the way
 * the capture does, over the last ten minutes, and record each outcome as that source's last read. Admin-only, gated by
 * the controller (AD-6).
 *
 * The read goes through the same sources as a capture ({@see CaptureSources}), so the same seams and the same typed
 * failures answer: no new HTTP client, and nothing is ever sent to a game server but a read. Who was present is thrown
 * away. Like the server fetch it writes no portal record an Admin chose, only what the services said, so it is not an
 * audited action.
 */
class TestTelemetryConnection
{
    /** How far back the test reads: long enough for a real answer, short enough to stay a light call. */
    private const WINDOW_MINUTES = 10;

    public function __construct(
        private readonly CaptureSources $sources,
        private readonly TelemetryConnectionRepository $connections,
    ) {}

    /**
     * One outcome per source, in the order a capture reads them: `ok`, `failed` with the typed reason, or
     * `not_configured` when its credentials are unset, or `no_server` when they are set but no server id is (neither
     * read nor recorded). Empty for a game read by no interface
     * or with nothing to read.
     *
     * @return list<array{server: string, interface: string, status: string, reason?: string}>
     */
    public function handle(Game $game): array
    {
        $end = new DateTimeImmutable;
        $start = $end->modify('-'.self::WINDOW_MINUTES.' minutes');
        $outcomes = [];

        foreach ($this->sources->for($game) as $server) {
            foreach ($server->sources as $source) {
                $interface = TelemetryInterface::from($source->name);
                $row = ['server' => $server->label, 'interface' => $interface->value];

                if (! $source->configured) {
                    $outcomes[] = [...$row, 'status' => $source->noServer ? 'no_server' : 'not_configured'];

                    continue;
                }

                try {
                    ($source->fetch)($start, $end);
                } catch (CaptureSourceUnavailable $e) {
                    Log::warning('Telemetry connection test failed.', ['gameId' => $game->id, 'server' => $server->label, 'reason' => $e->getMessage()]);
                    $this->connections->record($game->id, $source->gameServerId, $interface, false, $e->getMessage());
                    $outcomes[] = [...$row, 'status' => 'failed', 'reason' => $e->getMessage()];

                    continue;
                }

                $this->connections->record($game->id, $source->gameServerId, $interface, true, null);
                $outcomes[] = [...$row, 'status' => 'ok'];
            }
        }

        return $outcomes;
    }
}
