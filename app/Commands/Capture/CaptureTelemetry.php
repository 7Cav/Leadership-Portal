<?php

namespace App\Commands\Capture;

use App\Models\Game;
use DateTimeImmutable;

/**
 * Why a practice has no server data, for the rollcall screen (7.9, AD-20): either its game has no
 * source with env keys set (`NotConfigured`, nothing was attempted), or the last capture attempt
 * found no source that could answer (`Failed`, with when). A practice that was never attempted has
 * no state at all, so there is nothing to show.
 *
 * `feeds` names the sources a capture of the game tries (their `capture_source` values, in order), so the
 * screen can say which feed is down ("Hell Let Loose server (CRCON)") instead of a bare "server" that could
 * read as nobody having been online (#176). Empty when the game is unknown or has no source set up.
 */
final readonly class CaptureTelemetry
{
    /**
     * @param  list<string>  $feeds
     */
    private function __construct(
        public CaptureTelemetryState $state,
        public ?Game $game,
        public ?DateTimeImmutable $lastAttemptAt,
        public bool $canRetry,
        public array $feeds = [],
    ) {}

    public static function notConfigured(Game $game): self
    {
        return new self(CaptureTelemetryState::NotConfigured, $game, null, false);
    }

    /**
     * @param  list<string>  $feeds
     */
    public static function failed(?Game $game, DateTimeImmutable $lastAttemptAt, array $feeds = []): self
    {
        return new self(CaptureTelemetryState::Failed, $game, $lastAttemptAt, $game !== null, $feeds);
    }
}
