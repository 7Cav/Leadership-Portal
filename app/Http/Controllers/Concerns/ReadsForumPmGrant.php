<?php

namespace App\Http\Controllers\Concerns;

use App\Commands\Auth\ForumPmGrant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;

/**
 * The PM grant in the session (#565): the forum token that sends counseling PMs from the signed-in user's own account,
 * kept encrypted in their session only, never in the database (AD-6, AD-23), and gone with the session. A controller
 * reads it to hand to a counseling Command, and to tell the page whether the user still has to allow PMs.
 */
trait ReadsForumPmGrant
{
    private const PM_GRANT_KEY = 'forum_pm_grant';

    /** The live grant, or null when there is none, it expired, or it cannot be read. */
    protected function pmGrant(Request $request): ?ForumPmGrant
    {
        $stored = $request->session()->get(self::PM_GRANT_KEY);
        if (! is_string($stored)) {
            return null;
        }

        try {
            $grant = ForumPmGrant::fromArray(decrypt($stored));
        } catch (DecryptException) {
            $grant = null;
        }

        return $grant?->isLive() ? $grant : null;
    }

    protected function storePmGrant(Request $request, ForumPmGrant $grant): void
    {
        $request->session()->put(self::PM_GRANT_KEY, encrypt($grant->toArray()));
    }

    /** Forget a grant the forum no longer accepts, so the page asks again. */
    protected function forgetPmGrant(Request $request): void
    {
        $request->session()->forget(self::PM_GRANT_KEY);
    }
}
