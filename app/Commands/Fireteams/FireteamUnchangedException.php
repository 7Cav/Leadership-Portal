<?php

namespace App\Commands\Fireteams;

use RuntimeException;

/**
 * Raised inside the audited transaction when a fireteam change turns out, under the repository's lock, not to be the
 * one the handler set out to record: a racing request already took the trooper out of their fireteam, or changed
 * their role in between. Thrown rather than returned so it rolls the transaction back, which keeps work that did not
 * happen out of the audit log (AD-16), the same way as `GrantUnchangedException` for staff grants.
 * {@see SetFireteamRoleHandler} catches it; it never reaches a caller.
 */
final class FireteamUnchangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The fireteam row was no longer in the state the change was read against.');
    }
}
