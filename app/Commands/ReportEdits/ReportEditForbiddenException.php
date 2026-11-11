<?php

namespace App\Commands\ReportEdits;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not ask to edit this report (it is not theirs to send), or may not decide on this request (they do
 * not lead a unit above it). An `AuthorizationException`, so an HTTP caller that lets it propagate answers 403.
 */
class ReportEditForbiddenException extends AuthorizationException
{
    public static function request(): self
    {
        return new self('You may not ask to edit this report.');
    }

    public static function decide(): self
    {
        return new self('Only a leader above this unit, or an Admin, can decide on this request.');
    }
}
