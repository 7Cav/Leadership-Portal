<?php

namespace App\Models;

/**
 * What a counseling record is for, chosen when it is filed (2026-10-28), since the regulations run two ladders:
 *
 *  - **Missed practice**: a missed section practice or rollcall, under the accountability rules for minimum
 *    requirements (a DCS, then an LOR for a second miss within 30 days, a second LOR for a miss within 60 days of the
 *    first, then an Article 15).
 *  - **Conduct**: anything else, under the regimental disciplinary process (the 90-day DCS and LOR rules).
 *
 * A record filed before the portal asked has no kind (null) and is ruled as conduct. How the ladders are applied is
 * `Milpac\CounselingStanding`; this only names the two. Backed so the DB enum column and this type stay in lockstep.
 */
enum CounselingKind: string
{
    case MissedPractice = 'missed_practice';
    case Conduct = 'conduct';

    public function label(): string
    {
        return match ($this) {
            self::MissedPractice => 'Missed section practice or rollcall',
            self::Conduct => 'Other conduct',
        };
    }
}
