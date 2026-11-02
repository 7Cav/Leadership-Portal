<?php

namespace App\Docs;

/**
 * Which getting started tutorial steps the portal can tick off by itself, from what it knows the reader has already
 * done (the facts come from `DocsProgressRepository`, read by the controller). A step listed here shows as "Done in
 * the portal" and cannot be unticked; every other step keeps the reader's own tick. "Where to get help" has nothing to
 * check, so it stays a manual tick.
 */
final class PortalProgress
{
    /** Always true: a reader on a docs page is signed in. */
    public const SIGNED_IN = 'signed_in';

    /** fact => the tutorial step it ticks */
    private const MAP = [
        self::SIGNED_IN => 'sign-in-and-find-your-unit',
        'roster' => 'read-your-roster',
        'rollcall' => 'run-roll-call',
        'practice_slot' => 'set-your-practice-slot',
        'game_identity' => 'link-game-identities',
        'weekly_report' => 'send-the-weekly-report',
        'staff_report' => 'send-a-staff-report',
    ];

    /**
     * @param  list<string>  $facts  what the reader has done; signed in is added here
     * @return list<string> tutorial step ids
     */
    public static function done(array $facts): array
    {
        $steps = array_map(fn (string $fact) => self::MAP[$fact] ?? null, array_unique([self::SIGNED_IN, ...$facts]));

        return array_values(array_unique(array_filter($steps)));
    }
}
