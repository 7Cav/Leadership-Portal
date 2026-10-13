<?php

namespace App\Sync;

use RuntimeException;

/**
 * Cav answered a serving roster with no profiles while the cache still holds troopers on it who
 * appear on no other roster this run. Believing it would mark every one of them departed, and
 * leaders would lose their scope until a later sync put them back, so the run is refused instead
 * and nothing is written (AD-4: a sync never wipes the cache on the strength of a bad response).
 */
class EmptyRosterException extends RuntimeException
{
    public static function wouldDepart(string $rosterLabel, int $departing): self
    {
        return new self(sprintf(
            'Cav returned an empty %s roster, which would mark %d cached %s trooper%s departed. '
                .'Refusing the sync and writing nothing; the next run will retry.',
            $rosterLabel,
            $departing,
            $rosterLabel,
            $departing === 1 ? '' : 's',
        ));
    }
}
