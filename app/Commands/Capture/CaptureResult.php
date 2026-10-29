<?php

namespace App\Commands\Capture;

use App\Integration\Crcon\Data\CrconPlayer;

/**
 * What one capture run found, for leader review and for 7.6 (pre-mark) / 7.7 (resolve unmatched).
 * `unavailable` means the practice was left un-captured because no source could answer — never
 * zero attendance; `captured` with empty lists means a source answered and the window was empty.
 * `notConfigured` means no source's keys are set, so nothing was attempted (7.9).
 * `weekLocked` means the practice was left un-captured because the section's weekly report for
 * its week has been really sent (AD-12): that week's attendance is frozen, so no auto mark is
 * written and the practice is not stamped.
 *
 * A player linked to a trooper who has left the regiment (no longer serving) is `departed`: listed for the leader as
 * such, never credited and never marked, whatever section they were last in.
 */
final readonly class CaptureResult
{
    /**
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $matched  linked to a trooper of this section
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $otherSection  linked, but to a trooper whose primary section is elsewhere — surfaced, never credited
     * @param  list<CrconPlayer>  $unmatched  no link — surfaced for a human, never auto-assigned (AD-19)
     * @param  list<string>  $unavailableReasons
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $departed  linked to a trooper no longer serving: listed as departed, never credited
     */
    private function __construct(
        public bool $captured,
        public ?string $source,
        public array $matched,
        public array $otherSection,
        public array $unmatched,
        public array $unavailableReasons,
        public bool $attempted = true,
        public bool $weekLocked = false,
        public array $departed = [],
    ) {}

    /** The week's report has been sent: nothing captured, nothing written. */
    public static function weekLocked(): self
    {
        return new self(false, null, [], [], [], [], weekLocked: true);
    }

    /**
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $matched
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $otherSection
     * @param  list<CrconPlayer>  $unmatched
     * @param  list<array{player: CrconPlayer, trooperId: int}>  $departed
     */
    public static function captured(string $source, array $matched, array $otherSection, array $unmatched, array $departed = []): self
    {
        return new self(true, $source, $matched, $otherSection, $unmatched, [], departed: $departed);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function unavailable(array $reasons): self
    {
        return new self(false, null, [], [], [], $reasons);
    }

    /** No source has its env keys set: nothing was read and nothing recorded (7.9, AD-23). */
    public static function notConfigured(): self
    {
        return new self(false, null, [], [], [], ['no source is configured for this game'], attempted: false);
    }

    /** Players observed: every resolved or unresolved one. */
    public function seen(): int
    {
        return count($this->matched) + count($this->otherSection) + count($this->departed) + count($this->unmatched);
    }

    /** Players linked to any trooper, in this section or not, serving or departed. */
    public function matchedCount(): int
    {
        return count($this->matched) + count($this->otherSection) + count($this->departed);
    }

    public function unmatchedCount(): int
    {
        return count($this->unmatched);
    }
}
