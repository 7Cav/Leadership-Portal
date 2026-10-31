<?php

namespace App\Integration\Crcon;

use DateTimeImmutable;

/**
 * How long a player was on a server inside a window (#558): their stays clipped to the window, overlapping or
 * touching stays merged (a reconnect a minute later is one stay), summed and rounded down to whole minutes. Pure
 * arithmetic, so a presence source with per-player times can share it and it can be tested without a server.
 */
final class StayMinutes
{
    /**
     * @param  list<array{0: DateTimeImmutable, 1: DateTimeImmutable|null}>  $stays  start and end; a null end is still running at the window end
     */
    public static function within(array $stays, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $from = $start->getTimestamp();
        $till = $end->getTimestamp();
        $clipped = [];

        foreach ($stays as [$stayStart, $stayEnd]) {
            $a = max($from, $stayStart->getTimestamp());
            $b = min($till, $stayEnd?->getTimestamp() ?? $till);

            if ($b > $a) {
                $clipped[] = [$a, $b];
            }
        }

        usort($clipped, fn (array $x, array $y) => $x[0] <=> $y[0]);

        $seconds = 0;
        $open = null;

        foreach ($clipped as [$a, $b]) {
            if ($open !== null && $a <= $open[1]) {
                $open[1] = max($open[1], $b);

                continue;
            }

            if ($open !== null) {
                $seconds += $open[1] - $open[0];
            }

            $open = [$a, $b];
        }

        if ($open !== null) {
            $seconds += $open[1] - $open[0];
        }

        return intdiv($seconds, 60);
    }
}
