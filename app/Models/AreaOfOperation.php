<?php

namespace App\Models;

/**
 * The game / theatre a course belongs to. Backed so the stored `courses.category` value and this
 * type stay in lockstep — a value neither recognises fails loudly instead of landing in an
 * unlabelled group. `General` holds the courses tied to no single AO (NCOA, ODS and the like).
 * Case order is the display order of the groups.
 */
enum AreaOfOperation: string
{
    case General = 'general';
    case Battlefield6 = 'battlefield-6';
    case Arma3 = 'arma-3';
    case ArmaReforger = 'arma-reforger';
    case HllWw2 = 'hll-ww2';
    case HllWw2Console = 'hll-ww2-console';
    case HllVietnam = 'hll-vietnam';
    case DcsWorld = 'dcs-world';
    case Squad = 'squad';
    case Foxhole = 'foxhole';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Battlefield6 => 'Battlefield 6',
            self::Arma3 => 'Arma 3',
            self::ArmaReforger => 'Arma Reforger',
            self::HllWw2 => 'Hell Let Loose WW2',
            self::HllWw2Console => 'Hell Let Loose WW2 Console',
            self::HllVietnam => 'Hell Let Loose Vietnam',
            self::DcsWorld => 'DCS World',
            self::Squad => 'Squad',
            self::Foxhole => 'Foxhole',
        };
    }
}
