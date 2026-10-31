<?php

namespace App\Http\Controllers\Concerns;

use App\Authorization\RealActor;
use App\Commands\Auth\ForumPmGrant;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;

/**
 * The PM grant in the session (#565): the forum token that sends counseling PMs from the signed-in person's own account,
 * kept encrypted in their session only, never in the database (AD-6, AD-23), and gone with the session. A controller
 * reads it to hand to a counseling Command, and to tell the page whether the user still has to allow PMs. It serves only
 * the person really signed in ({@see RealActor}: while acting as someone, the one acting), whose forum account it is.
 */
trait ReadsForumPmGrant
{
    private const PM_GRANT_KEY = 'forum_pm_grant';

    /** The live grant of the person really signed in, or null when there is none, it expired, or it is not theirs. */
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

        $forumUserId = $this->pmSender($request)?->forum_user_id;

        return $grant !== null && $grant->isLive() && $grant->servesForumUser($forumUserId === null ? null : (int) $forumUserId) ? $grant : null;
    }

    /** Whose forum account sends the PMs: the person really signed in. */
    protected function pmSender(Request $request): ?User
    {
        return app(RealActor::class)->get() ?? $request->user();
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
