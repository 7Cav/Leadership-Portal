<?php

namespace App\Models;

/**
 * How a game's attendance is read (#388): which of the portal's built-in telemetry seams answers for it, or none.
 * The seams stay code, one `Integration/` seam each (AD-3); an Admin picks one for a game, never defines a new one.
 * The capture's source map, `App\Commands\Capture\CaptureSources`, maps each to the sources a capture tries.
 *
 * A game's servers are picked on the Servers page (#389) and each is read by its own interface; credentials stay in
 * env, one set per interface (AD-23). A game with no server selected reads its interface's legacy env server when that
 * server is its own (`CaptureSources::LEGACY_GAMES`), else nothing, and a game set to `none` is not captured at all.
 *
 * `none` means reading is not set up yet; `impossible` means it can never be (a game with no server to read, such as
 * Battlefield 6). Neither is captured. Reports for an `impossible` game say once that attendance is self-reported
 * instead of flagging each missing check.
 */
enum TelemetryInterface: string
{
    case Crcon = 'crcon';
    case BattleMetrics = 'battlemetrics';
    case Nightwatch = 'nightwatch';
    case None = 'none';
    case Impossible = 'impossible';

    /** How the interface is named on screen. */
    public function label(): string
    {
        return match ($this) {
            self::Crcon => 'CRCON',
            self::BattleMetrics => 'BattleMetrics',
            self::Nightwatch => 'Nightwatch',
            self::None => 'None',
            self::Impossible => 'None (impossible)',
        };
    }

    /** Whether the interface reads anything: false for `none` and `impossible`. */
    public function reads(): bool
    {
        return $this !== self::None && $this !== self::Impossible;
    }
}
