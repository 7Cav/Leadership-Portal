<?php

namespace App\Commands\AdminBillets;

use DomainException;

/**
 * A decision on an Admin billet the domain rules refuse: the row is gone, it is the decider's own billet, it already
 * stands as asked, its group has not been seen yet, or its group cannot make an Admin. Domain rules, not request
 * shape, so the controller turns this into a form error.
 */
class InvalidAdminBilletDecisionException extends DomainException
{
    public static function gone(): self
    {
        return new self('That billet is no longer listed. Reload the page.');
    }

    public static function ownBillet(): self
    {
        return new self('You hold this billet, so another Admin has to decide on it.');
    }

    public static function alreadyApproved(): self
    {
        return new self('This billet is already approved with these names.');
    }

    public static function alreadyRejected(): self
    {
        return new self('This billet is already rejected.');
    }

    public static function namesUnknown(): self
    {
        return new self('This billet\'s title or group is not known yet. Run the org import, then approve it.');
    }

    public static function notAdminGroup(): self
    {
        return new self('This billet no longer has a group or title that can make an Admin, so it cannot be approved.');
    }
}
