<?php

namespace App\Models;

/**
 * A trooper's signature on the week's rollcall thread ("I will attend…" / "I cannot attend…"),
 * tracked independently of the Section Practice `status`: signing is not attending. `Unsigned`
 * is the default for a row created before (or without) a signature. RC % (5.1) counts rows that
 * are anything other than `Unsigned`.
 */
enum RollcallSignature: string
{
    case Unsigned = 'unsigned';
    case WillAttend = 'will_attend';
    case CannotAttend = 'cannot_attend';
}
