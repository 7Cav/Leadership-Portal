<?php

namespace App\Models;

/**
 * How a game's attendance is read (#388): which of the portal's built-in telemetry seams answers for it, or none.
 * The seams stay code, one `Integration/` seam each (AD-3); an Admin picks one for a game, never defines a new one.
 * The capture's source map, `App\Commands\Capture\CaptureSources`, maps each to the sources a capture tries.
 *
 * Each interface reads the server(s) configured for it in env (AD-23), not one per game: picking BattleMetrics for a
 * new game reads the BattleMetrics server already configured. Per-game servers are a later change (#389).
 */
enum TelemetryInterface: string
{
    case Crcon = 'crcon';
    case BattleMetrics = 'battlemetrics';
    case Nightwatch = 'nightwatch';
    case None = 'none';

    /** How the interface is named on screen. */
    public function label(): string
    {
        return match ($this) {
            self::Crcon => 'CRCON',
            self::BattleMetrics => 'BattleMetrics',
            self::Nightwatch => 'Nightwatch',
            self::None => 'None',
        };
    }
}
