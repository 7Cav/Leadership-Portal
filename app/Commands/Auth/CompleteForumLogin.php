<?php

namespace App\Commands\Auth;

use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Models\User;
use App\Repositories\UserRepository;

/**
 * Completes the OAuth2 handshake: exchange code → fetch forum profile → upsert the
 * portal identity row, returning the resolved {@see User}. Pure business logic —
 * the session/auth lifecycle (`Auth::login`, `session()->regenerate()`) stays in
 * the controller. Every forum-side failure the seam raises (unreachable host, HTTP
 * error status, or malformed/unparseable payload) arrives here as a
 * {@see ForumRequestException} and is rethrown as a {@see ForumLoginException}, so
 * the controller can produce AC #5's response instead of a 500. A DB failure on the
 * upsert is deliberately left to bubble — that is not a forum failure.
 */
class CompleteForumLogin
{
    public function __construct(
        private readonly ForumApi $forum,
        private readonly UserRepository $users,
    ) {}

    public function handle(string $code): User
    {
        try {
            $token = $this->forum->exchangeCode($code);
            $profile = $this->forum->fetchProfile($token);
        } catch (ForumRequestException $e) {
            throw new ForumLoginException('Could not complete sign-in with the 7Cav forum.', previous: $e);
        }

        return $this->users->upsertFromForumIdentity(
            forumUserId: $profile->forumUserId,
            username: $profile->username,
            email: $profile->email,
        );
    }
}
