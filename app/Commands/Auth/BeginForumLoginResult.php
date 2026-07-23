<?php

namespace App\Commands\Auth;

/**
 * The output of {@see BeginForumLogin}: where to send the browser, and the CSRF
 * `state` nonce the controller must stash in the session to verify on callback.
 */
readonly class BeginForumLoginResult
{
    public function __construct(
        public string $authorizeUrl,
        public string $state,
    ) {}
}
