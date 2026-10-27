<?php

namespace App\Models;

/**
 * What a node of the org tree is for (decided by the product owner 2026-10-24). Every echelon of the chain of command
 * (regiment down to section) is `Line`: the units that hold rollcalls, report and are measured (AD-14, AD-21). The rest
 * are `UnitType::Group` nodes the org import creates so every serving trooper has a place:
 *
 *  - `Staff`: Development Command and its departments (S1, S2, S3, S5, S6, S7, WAG, MP, RRD, RTC, DEVCOM staff);
 *  - `Eloa`: troopers on Extended Leave of Absence, who keep the line unit they left as "was";
 *  - `Reserve`: the 7th Cavalry Reservists;
 *  - `Recruit`: New Recruits, hidden by default on the org page and roster, and never in an ancestor's count.
 *
 * A group is counted in strength on the org page and roster, but has no rollcall, no SP/RC, no weekly report, no capture
 * and is never a guest or makeup target. Backed so the DB column and this type stay in lockstep.
 */
enum UnitKind: string
{
    case Line = 'line';
    case Staff = 'staff';
    case Eloa = 'eloa';
    case Reserve = 'reserve';
    case Recruit = 'recruit';

    public function isLine(): bool
    {
        return $this === self::Line;
    }
}
