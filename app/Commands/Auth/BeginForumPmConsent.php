<?php

namespace App\Commands\Auth;

use App\Integration\Forum\ForumApi;
use Illuminate\Support\Str;

/**
 * Staged action (AD-15), the PM consent's first leg (#565): mints a single-use `state` nonce and builds the forum
 * authorize URL asking for the conversation scope on top of the sign-in scopes, so the portal can send the counseling
 * PMs from the user's own forum account. A Command for the same reason {@see BeginForumLogin} is one: the controller
 * cannot reach the Forum seam.
 */
class BeginForumPmConsent
{
    public function __construct(private readonly ForumApi $forum) {}

    public function handle(): BeginForumLoginResult
    {
        $state = Str::random(40);

        return new BeginForumLoginResult(
            authorizeUrl: $this->forum->conversationAuthorizeUrl($state),
            state: $state,
        );
    }
}
