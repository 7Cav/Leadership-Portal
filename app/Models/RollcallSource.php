<?php

namespace App\Models;

/**
 * Who set a trooper's roll call signature (`attendance.rollcall_source`), as {@see AttendanceSource} says who set a
 * practice mark. The values mirror the DB enum by hand: change both together. `Manual` is a leader on the roll call
 * page, `Reply` the reader of the week's thread replies. A leader's signature is never overwritten by a reply; a reply's
 * signature follows the trooper's latest reply. Null on a row nobody signed.
 */
enum RollcallSource: string
{
    case Manual = 'manual';
    case Reply = 'reply';
}
