<?php

namespace App\Models;

/**
 * Where a DCS or LOR stands in the wiki procedure the leader drives (#565). Only these three steps are stored; every
 * later one (acknowledged, no response, appealed, decided, frozen) is worked out on read from the record's timestamps,
 * so none of them needs a job ({@see CounselingRecord::state()}).
 *
 *  - **Draft**: being written. Seen only by the issuer, their chain and Admins; deleted freely.
 *  - **Invited**: still a draft, but the wiki's meeting PM went to the trooper; the 72-hour meeting window runs.
 *  - **Shared**: the leader's part is locked, Section 1 snapshotted and the After-Action PM sent. The trooper sees it.
 *
 * Withdrawing a shared record takes it back to Draft. Backed so the DB enum column and this type stay in lockstep.
 */
enum CounselingStatus: string
{
    case Draft = 'draft';
    case Invited = 'invited';
    case Shared = 'shared';

    public function isDraft(): bool
    {
        return $this !== self::Shared;
    }
}
