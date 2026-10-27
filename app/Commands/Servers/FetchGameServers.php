<?php

namespace App\Commands\Servers;

use App\Commands\Capture\TelemetryCredentials;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\ListedServer;
use App\Integration\Nightwatch\NightwatchApi;
use App\Integration\Nightwatch\NightwatchRequestRefused;
use App\Integration\Nightwatch\NightwatchUnavailable;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Ask each telemetry interface which game servers it can see, and store them (#389): BattleMetrics lists the servers
 * its key has RCON access to, Nightwatch the workspace's servers, CRCON the one server its instance runs. Admin-only,
 * gated by the controller (AD-6).
 *
 * An interface without credentials is skipped, and one that cannot answer is reported and leaves its stored servers
 * as they are: only a listing that came back whole changes anything. A new server arrives unselected and public; a
 * stored one the listing left out is flagged missing, never deselected. The listing is a copy of what the API says,
 * like a roster sync, so it is not an audited action; what an Admin then chooses is ({@see UpdateGameServer}).
 */
class FetchGameServers
{
    public function __construct(
        private readonly CrconApi $crcon,
        private readonly BattleMetricsApi $battleMetrics,
        private readonly NightwatchApi $nightwatch,
        private readonly TelemetryCredentials $credentials,
        private readonly GameServerRepository $servers,
    ) {}

    /**
     * One outcome per interface, in a fixed order: `listed` with how many it listed, added and found missing;
     * `not_configured` when its credentials are unset; `failed` with why.
     *
     * @return list<array{interface: string, status: string, listed?: int, added?: int, missing?: int, message?: string}>
     */
    public function handle(): array
    {
        $outcomes = [];

        foreach ($this->listers() as $value => $list) {
            $interface = TelemetryInterface::from($value);

            if (! $this->credentials->areSet($interface)) {
                $outcomes[] = ['interface' => $value, 'status' => 'not_configured'];

                continue;
            }

            try {
                $listed = $list();
            } catch (CrconUnavailable|BattleMetricsUnavailable|NightwatchUnavailable|NightwatchRequestRefused $e) {
                Log::warning('Game server listing unavailable.', ['interface' => $value, 'reason' => $e->getMessage()]);
                $outcomes[] = ['interface' => $value, 'status' => 'failed', 'message' => $e->getMessage()];

                continue;
            }

            // An empty listing while servers are stored is far likelier a key that lost its scope, or an answer that
            // changed shape, than every server gone at once: it is reported, and flags nothing missing.
            if ($listed === [] && $this->servers->all()->contains(fn ($server) => $server->interface === $interface)) {
                $outcomes[] = ['interface' => $value, 'status' => 'failed', 'message' => 'It listed no servers, so the stored ones were left as they are.'];

                continue;
            }

            $stored = $this->servers->storeListing($interface, array_map(
                fn (ListedServer $server) => ['external_id' => $server->externalId, 'name' => $server->name],
                $listed,
            ));

            $outcomes[] = ['interface' => $value, 'status' => 'listed', 'listed' => count($listed), ...$stored];
        }

        return $outcomes;
    }

    /**
     * Each interface's listing call. An exhaustive `match`, so a new interface case without one fails here instead
     * of never being listed.
     *
     * @return array<string, Closure(): list<ListedServer>>
     */
    private function listers(): array
    {
        $listers = [];

        foreach (TelemetryInterface::cases() as $interface) {
            $lister = match ($interface) {
                TelemetryInterface::Crcon => fn () => $this->crcon->listServers(),
                TelemetryInterface::BattleMetrics => fn () => $this->battleMetrics->listServers(),
                TelemetryInterface::Nightwatch => fn () => $this->nightwatch->listServers(),
                TelemetryInterface::None => null,
            };

            if ($lister !== null) {
                $listers[$interface->value] = $lister;
            }
        }

        return $listers;
    }
}
