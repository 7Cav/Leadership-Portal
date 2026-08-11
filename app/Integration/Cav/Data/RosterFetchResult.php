<?php

namespace App\Integration\Cav\Data;

/**
 * The outcome of a full-roster sweep: every trooper that parsed cleanly, plus every row that
 * did not. A roster of ~800 troopers reliably carries a handful of stale rows, and demanding a
 * perfect sweep would mean the sync never completes — so a per-row failure is data the caller
 * acts on, not a reason to discard the other 799 rows (AC #6).
 *
 * `$troopers` is a plain array, not a `Collection`: a `readonly` property only stops
 * reassignment, and a `Collection` is a mutable object a caller could still `push()`/`forget()`
 * through — a plain array is a value type, so this result is genuinely immutable once returned.
 */
readonly class RosterFetchResult
{
    /**
     * @param  list<CavRosterEntry>  $troopers
     * @param  list<RosterRowError>  $errors
     */
    public function __construct(
        public array $troopers,
        public array $errors,
    ) {}
}
