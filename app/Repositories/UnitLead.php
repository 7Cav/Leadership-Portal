<?php

namespace App\Repositories;

use App\Models\Trooper;

/**
 * Who leads a unit right now, and why (decided 2026-10-25): the serving holder of a command billet over it
 * (`billet`), the serving holder of the billet a staff portfolio is named for (`portfolio`), or a serving trooper an
 * Admin assigned as its acting lead (`acting`), in that order of precedence.
 */
final readonly class UnitLead
{
    public const BILLET = 'billet';

    public const PORTFOLIO = 'portfolio';

    public const ACTING = 'acting';

    public function __construct(
        public Trooper $trooper,
        public string $source,
    ) {}
}
