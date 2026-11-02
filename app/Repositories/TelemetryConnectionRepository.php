<?php

namespace App\Repositories;

use App\Models\TelemetryConnection;
use App\Models\TelemetryInterface;

/**
 * Sole DB access for `telemetry_connections` (AD-2, #183): the last outcome of each source a game reads. Portal-owned
 * (AD-22): the capture run and `TestTelemetryConnection` record outcomes; the Game server connections page reads them.
 */
interface TelemetryConnectionRepository
{
    /**
     * Record one read of a source: a success sets when it last answered, a failure when and why it last failed. The
     * other half is kept, so the page shows both. `$gameServerId` is null for the server set in env.
     */
    public function record(int $gameId, ?int $gameServerId, TelemetryInterface $interface, bool $succeeded, ?string $reason): void;

    /**
     * Every recorded source, keyed by game id, then by {@see TelemetryConnection::targetFor()}.
     *
     * @return array<int, array<string, TelemetryConnection>>
     */
    public function allByGame(): array;
}
