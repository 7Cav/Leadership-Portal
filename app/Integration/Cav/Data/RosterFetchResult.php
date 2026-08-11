<?php

namespace App\Integration\Cav\Data;

use Illuminate\Support\Collection;

/**
 * The outcome of a full-roster sweep: every trooper that parsed cleanly, plus every row that
 * did not. A roster of ~800 troopers reliably carries a handful of stale rows, and demanding a
 * perfect sweep would mean the sync never completes — so a per-row failure is data the caller
 * acts on, not a reason to discard the other 799 rows (AC #6).
 */
readonly class RosterFetchResult
{
    /**
     * @param  Collection<int, TrooperDto>  $troopers
     * @param  list<RosterRowError>  $errors
     */
    public function __construct(
        public Collection $troopers,
        public array $errors,
    ) {}
}
