<?php

namespace App\Commands\Guests;

use Carbon\CarbonImmutable;

/**
 * Undo a hand-added guest: the host leader takes back their word that `$trooperId` attended `$sectionId`'s practice in
 * the Cav week holding `$week`. Only a `manual` entry can be removed; what the game server saw stays.
 */
final readonly class RemoveGuestAttendance
{
    public function __construct(
        public int $sectionId,
        public CarbonImmutable $week,
        public int $trooperId,
    ) {}
}
