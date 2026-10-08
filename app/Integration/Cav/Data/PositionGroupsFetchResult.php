<?php

namespace App\Integration\Cav\Data;

use DateTimeImmutable;

/**
 * Every Cav position group, in Cav's display order, stamped with the time of its fetch (AD-20).
 */
readonly class PositionGroupsFetchResult
{
    /**
     * @param  list<CavPositionGroup>  $groups
     */
    public function __construct(
        public array $groups,
        public DateTimeImmutable $fetchedAt,
    ) {}
}
