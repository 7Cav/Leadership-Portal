<?php

namespace App\Commands\Auth;

/**
 * Whether a forum identity may start a portal session, as {@see AdmitServingMember} decides it.
 * The two refusals are kept apart because they tell the user different things: one is a fact
 * about them, the other only a fact about the roster API right now.
 */
enum Admission
{
    /** An Admin, or linked to a serving trooper — sign them in. */
    case Admitted;

    /** Known not to be serving: no milpac link, or one to a trooper the roster cache shows departed. */
    case NotServing;

    /**
     * Neither established nor ruled out: the user has no link the local roster cache knows, and Cav
     * could not be asked. Trying again once the roster is back may well admit them.
     */
    case Unverifiable;
}
