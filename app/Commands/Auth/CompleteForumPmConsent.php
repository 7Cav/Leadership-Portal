<?php

namespace App\Commands\Auth;

use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The PM consent's second leg (#565): exchange the code for a token, then check, through the forum profile, that the
 * token belongs to the user signed in to the portal. Anyone else's token (signed in to the forum as someone else in the
 * same browser) is refused, so a PM never goes out under another person's name. The token is handed back for the
 * session; nothing is written here. Every forum-side failure becomes a {@see ForumLoginException}.
 */
class CompleteForumPmConsent
{
    public function __construct(private readonly ForumApi $forum) {}

    /**
     * @throws ForumLoginException
     */
    public function handle(User $user, #[\SensitiveParameter] string $code): ForumPmGrant
    {
        try {
            $token = $this->forum->exchangeCode($code);
            $profile = $this->forum->fetchProfile($token);
        } catch (ForumRequestException $e) {
            throw new ForumLoginException('The 7Cav forum did not allow the portal to send PMs for you.', previous: $e);
        }

        if ($user->forum_user_id === null || (int) $profile->forumUserId !== (int) $user->forum_user_id) {
            throw new ForumLoginException('You are signed in to the 7Cav forum as someone else. Sign in there as yourself and try again.');
        }

        return new ForumPmGrant(
            accessToken: $token->accessToken,
            expiresAt: CarbonImmutable::now()->addSeconds($token->expiresIn ?? ForumPmGrant::DEFAULT_HOURS * 3600),
        );
    }
}
