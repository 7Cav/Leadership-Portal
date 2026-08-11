<?php

namespace App\Integration\Cav\Data;

/**
 * A single roster entry that could not be turned into a {@see CavRosterEntry}. Carried rather than
 * thrown (AC #6): one bad row must not cost the caller the rest of the roster, but it must not
 * be silent either — the sync run records it in its counts.
 */
readonly class RosterRowError
{
    public function __construct(
        /** The roster map key the unusable entry was found under. */
        public int|string $key,
        public string $reason,
    ) {}
}
