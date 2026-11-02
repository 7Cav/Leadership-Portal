<?php

namespace App\Commands\Units;

/**
 * How a move settles a designation clash: the unit moved under a new parent rebuilds its callsign from that parent, and
 * a unit there already holds it ({@see MoveUnit}).
 */
enum ClashResolution: string
{
    /** The two trade places: the unit already there moves to the moved unit's old parent and takes its old callsign. */
    case Swap = 'swap';

    /** The unit already there stays and takes another free number or letter under the same parent. */
    case RenumberExisting = 'renumber_existing';

    /** The moved unit takes another free number or letter under its new parent instead. */
    case RenumberMoved = 'renumber_moved';
}
