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
 *
 * `$emptyRosters` names each serving roster that answered with no profiles at all. The seam
 * cannot tell an empty roster from a broken one on its own (both are a well-formed, empty
 * `profiles` map), so it reports the fact and leaves the judgement to the sync handler, which
 * knows who the cache last saw on that roster.
 */
readonly class RosterFetchResult
{
    /**
     * @param  list<CavRosterEntry>  $troopers
     * @param  list<RosterRowError>  $errors
     * @param  list<RosterType>  $emptyRosters
     */
    public function __construct(
        public array $troopers,
        public array $errors,
        public array $emptyRosters = [],
    ) {}
}
