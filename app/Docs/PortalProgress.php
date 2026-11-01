<?php

namespace App\Docs;

/**
 * Which tutorial steps and Start here items the portal can tick off by itself, from what it knows the reader has
 * already done (the facts come from `DocsProgressRepository`, read by the controller). A step listed here shows as
 * "Done in the portal" and cannot be unticked; every other step keeps the reader's own tick. "Where to get help" has
 * nothing to check, so it stays a manual tick.
 */
final class PortalProgress
{
    /** Always true: a reader on a docs page is signed in. */
    public const SIGNED_IN = 'signed_in';

    /** fact => [tutorial step slugs, Start here item ids] */
    private const MAP = [
        self::SIGNED_IN => [['sign-in-and-find-your-unit'], ['basics/sign-in']],
        'roster' => [['read-your-roster'], ['basics/read-the-roster']],
        'rollcall' => [['run-roll-call'], ['weekly-rollcall/run-rollcall']],
        'practice_slot' => [['set-your-practice-slot'], []],
        'game_identity' => [['link-game-identities'], ['your-troopers/game-identities']],
        'weekly_report' => [['send-the-weekly-report'], ['weekly-report/send-your-report']],
        'staff_report' => [['send-a-staff-report'], []],
    ];

    /**
     * @param  list<string>  $facts  what the reader has done; signed in is added here
     * @return array{tutorial: list<string>, checklist: list<string>}
     */
    public static function done(array $facts): array
    {
        $tutorial = [];
        $checklist = [];
        foreach (array_unique([self::SIGNED_IN, ...$facts]) as $fact) {
            [$steps, $items] = self::MAP[$fact] ?? [[], []];
            array_push($tutorial, ...$steps);
            array_push($checklist, ...$items);
        }

        return ['tutorial' => $tutorial, 'checklist' => $checklist];
    }
}
