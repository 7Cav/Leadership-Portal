<?php

namespace App\Commands\WeeklyReports;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor's write scope (AD-8, `ScopeResolver::writeScope()`) does not cover the unit whose report is
 * being sent — or the unit does not exist, which no scope covers. Enforced in the handler, so a
 * send outside scope is refused whatever the UI allowed. An `AuthorizationException`, so any HTTP
 * caller that lets it propagate answers 403.
 */
class SendWeeklyReportForbiddenException extends AuthorizationException
{
    public function __construct(int $unitId)
    {
        parent::__construct("You may not send the weekly report for unit {$unitId}: it is outside your scope.");
    }
}
