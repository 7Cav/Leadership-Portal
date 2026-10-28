<?php

namespace App\Models;

/**
 * The two counseling records the regulations name (#381). Backed so the DB enum column and this type stay in lockstep.
 *
 *  - A **Developmental Counseling Statement** may be filed by any leader on a trooper in their command, and is seen by
 *    the trooper's chain from Platoon Leader up plus their own Section Leader.
 *  - A **Letter of Reprimand** may be filed only by command from platoon level up, and is seen by everyone who can see
 *    the trooper.
 *
 * Who may do what is `Authorization\CounselingAccess`; this only names the two.
 */
enum CounselingType: string
{
    case Dcs = 'dcs';
    case Lor = 'lor';

    public function label(): string
    {
        return match ($this) {
            self::Dcs => 'Developmental Counseling Statement',
            self::Lor => 'Letter of Reprimand',
        };
    }

    public function short(): string
    {
        return strtoupper($this->value);
    }
}
