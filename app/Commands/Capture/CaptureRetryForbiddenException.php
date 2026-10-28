<?php

namespace App\Commands\Capture;

use Illuminate\Auth\Access\AuthorizationException;

/** The actor's scope (AD-8) does not cover the section whose practice they asked to capture again. */
class CaptureRetryForbiddenException extends AuthorizationException
{
    public function __construct(int $sectionId)
    {
        parent::__construct('You may not capture the practice of this section: it is outside your scope.');
    }
}
