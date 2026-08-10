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
}
