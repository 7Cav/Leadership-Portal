<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;
use App\Models\AppealOutcome;
use InvalidArgumentException;

/**
 * The appeal authority decides an appealed LOR (#565): HAVE AMENDED, with the consequence as amended (`$amended`, only
 * its consequence fields are read), or HAVE NOT AMENDED, each with a required reason. `$pm` sends the decision from
 * their own forum account. Handled by {@see DecideCounselingAppealHandler}.
 */
final readonly class DecideCounselingAppeal
{
    /**
     * @throws InvalidArgumentException when an amendment comes without the consequence as amended, or the other way round
     */
    public function __construct(
        public int $recordId,
        public AppealOutcome $outcome,
        public string $reason,
        public ?CounselingForm $amended,
        public ?ForumPmGrant $pm,
    ) {
        if (($outcome === AppealOutcome::Amended) !== ($amended !== null)) {
            throw new InvalidArgumentException('An amended decision carries the consequence as amended, and only an amended one.');
        }
    }
}
