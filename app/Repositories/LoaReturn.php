<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;

/**
 * An "[ELOA Return]" thread: it ends the trooper's open ELOA on `returnedOn`. Not a leave period, so it
 * is never stored as a row of its own. Immutable.
 */
final readonly class LoaReturn
{
    public function __construct(
        public int $returnThreadId,
        public int $trooperId,
        public CarbonImmutable $returnedOn,
    ) {}
}
