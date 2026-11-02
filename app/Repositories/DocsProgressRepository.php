<?php

namespace App\Repositories;

/**
 * What the portal already knows a person has done, for the getting started tutorial to tick off by itself.
 * Read-only, and a few small queries per page whatever the number of steps: one over the audit log, and
 * one existence check each for the roster visit, the section's practice slot and the staff report.
 */
interface DocsProgressRepository
{
    /** The person opened the roster (the activity log keeps 30 days). */
    public const ROSTER = 'roster';

    /** The person marked attendance, confirmed a section practice or marked a roll call posted. */
    public const ROLLCALL = 'rollcall';

    /** The person set a practice slot, or the section they lead has one. */
    public const PRACTICE_SLOT = 'practice_slot';

    /** The person linked at least one game identity. */
    public const GAME_IDENTITY = 'game_identity';

    /** The person sent a weekly report. */
    public const WEEKLY_REPORT = 'weekly_report';

    /** The person sent a staff report, or one was sent for them. */
    public const STAFF_REPORT = 'staff_report';

    /**
     * Which of the facts above hold. `$userId` is the person's account (null for a trooper who has never signed in),
     * `$cavId` their milpac, `$sectionId` the unit they lead. A write an Admin made while acting as the person counts
     * as the person's, not the Admin's.
     *
     * @return list<string>
     */
    public function factsFor(?int $userId, ?int $cavId, ?int $sectionId): array;
}
