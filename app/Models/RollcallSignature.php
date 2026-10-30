<?php

namespace App\Models;

/**
 * A trooper's signature on the week's roll call thread, tracked independently of the Section Practice `status`:
 * signing is not attending. `Unsigned` is the default for a row created before (or without) a signature. The values
 * mirror the `attendance.rollcall` DB enum by hand — change both together.
 *
 * On screen (2026-10-30): `WillAttend` is "Will attend", `CannotAttend` "Will not attend", `MakeupRequested`
 * "Make-up requested" (signed, and owes a make-up for the week until one is recorded) and `Excused` "Excused" (the week
 * leaves the RC rate, like leave). RC % (5.1) counts {@see isSigned()} over the weeks that are not excused.
 */
enum RollcallSignature: string
{
    case Unsigned = 'unsigned';
    case WillAttend = 'will_attend';
    case CannotAttend = 'cannot_attend';
    case MakeupRequested = 'makeup_requested';
    case Excused = 'excused';

    /** Counts as signed for RC %: an answer on the thread. `Excused` is not an answer; it takes the week out instead. */
    public function isSigned(): bool
    {
        return $this === self::WillAttend || $this === self::CannotAttend || $this === self::MakeupRequested;
    }
}
