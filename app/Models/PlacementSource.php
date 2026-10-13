<?php

namespace App\Models;

/**
 * Who set a trooper's `primary_section_id` (2.7). `Sync` is the roster sync's billet mapping (2.5);
 * `Manual` is an Admin's audited placement. Backed so the DB enum column and this type stay in
 * lockstep.
 */
enum PlacementSource: string
{
    case Sync = 'sync';
    case Manual = 'manual';
}
