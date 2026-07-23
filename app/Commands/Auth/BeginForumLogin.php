<?php

namespace App\Commands\Auth;

use App\Integration\Forum\ForumApi;
use Illuminate\Support\Str;

/**
 * Staged action (AD-15): mints a single-use `state` nonce and builds the forum
 * authorize URL. The controller cannot reach the Forum seam directly (Deptrac:
 * Controller → CommandHandler → Integration), so even this read-shaped step is a
 * Command — it is the legitimate place the CSRF nonce is created.
 */
class BeginForumLogin
{
    public function __construct(private readonly ForumApi $forum) {}

    public function handle(): BeginForumLoginResult
    {
        $state = Str::random(40);

        return new BeginForumLoginResult(
            authorizeUrl: $this->forum->authorizeUrl($state),
            state: $state,
        );
    }
}
