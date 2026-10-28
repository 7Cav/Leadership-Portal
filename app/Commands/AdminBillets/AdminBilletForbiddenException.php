<?php

namespace App\Commands\AdminBillets;

use RuntimeException;

/**
 * Only an Admin, by flag or by an approved billet, confirms or rejects an Admin billet (AD-6).
 */
class AdminBilletForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only an Admin may confirm or reject an Admin billet.');
    }
}
