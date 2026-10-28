<?php

namespace App\Commands\WeeklyReports;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor does not command the platoon whose training topic they tried to set (or it is outside
 * their scope, or does not exist). Only the platoon's own chain of command, or an Admin, sets it: a
 * staff grant lets someone read a unit, not write to it. An `AuthorizationException`, so an HTTP
 * caller that lets it propagate answers 403.
 */
class PlatoonTrainingTopicForbiddenException extends AuthorizationException
{
    public function __construct(int $unitId)
    {
        parent::__construct('You may not set the training topic for this platoon: you do not command it.');
    }
}
