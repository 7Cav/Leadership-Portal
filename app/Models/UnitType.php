<?php

namespace App\Models;

/**
 * The echelon of a node in the chain of command (AD-18). Backed so the DB enum column and this
 * type stay in lockstep — a value neither recognises fails loudly instead of rendering as an
 * unlabelled node.
 */
enum UnitType: string
{
    case Regiment = 'regiment';
    case Battalion = 'battalion';
    case Company = 'company';
    case Platoon = 'platoon';
    case Section = 'section';

    /**
     * Depth in the chain of command, Regiment first. The tree's actual invariant isn't "one of
     * these five strings" — it's that a child sits exactly one rank below its parent, which the
     * write-side Command layer enforces using this. Not named directly here (see the `Unit`
     * model docblock: a `{@see}` reference becomes a real, Deptrac-forbidden import).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Regiment => 0,
            self::Battalion => 1,
            self::Company => 2,
            self::Platoon => 3,
            self::Section => 4,
        };
    }
}
