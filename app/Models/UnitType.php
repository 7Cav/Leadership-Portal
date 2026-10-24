<?php

namespace App\Models;

/**
 * The echelon of a node in the chain of command (AD-18). Backed so the DB enum column and this
 * type stay in lockstep — a value neither recognises fails loudly instead of rendering as an
 * unlabelled node.
 *
 * `Group` is not an echelon: it is a staff department, Development Command, or a holding group (ELOA, Reservists, New
 * Recruits), with its `UnitKind` saying which. Groups sit off the line, so no rule that asks for a section, platoon or
 * company ever matches one: they have no rollcall, practice, report, template or fireteam.
 */
enum UnitType: string
{
    case Regiment = 'regiment';
    case Battalion = 'battalion';
    case Company = 'company';
    case Platoon = 'platoon';
    case Section = 'section';
    case Group = 'group';

    /**
     * Depth in the chain of command, Regiment first. The tree's actual invariant isn't "one of
     * these five strings" — it's that a child sits exactly one rank below its parent, which the
     * write-side Command layer enforces through {@see canSitUnder()}. A group has no rank; it is
     * given the battalion's so a caller sorting by rank keeps it under the regiment.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Regiment => 0,
            self::Battalion, self::Group => 1,
            self::Company => 2,
            self::Platoon => 3,
            self::Section => 4,
        };
    }

    public function isLine(): bool
    {
        return $this !== self::Group;
    }

    /**
     * The placement rule: a line echelon sits exactly one rank below a line parent, unchanged since 1.8; a group sits
     * under the regiment or under another group (a department under Development Command), and nothing on the line ever
     * sits under a group.
     */
    public function canSitUnder(self $parent): bool
    {
        if ($this === self::Group) {
            return $parent === self::Regiment || $parent === self::Group;
        }

        return $parent->isLine() && $this->rank() === $parent->rank() + 1;
    }
}
