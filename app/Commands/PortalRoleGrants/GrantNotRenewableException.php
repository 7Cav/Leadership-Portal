<?php

namespace App\Commands\PortalRoleGrants;

use RuntimeException;

/** Only a grant set to lapse at its date and wait for renewal can be renewed; one that ends for good is edited instead. */
final class GrantNotRenewableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This grant has no renewal date. Edit its end date instead.');
    }
}
