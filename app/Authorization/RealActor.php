<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Container\Attributes\Scoped;

/**
 * Who is really signed in on this request while they act as someone ({@see ActAs}): the Admin, or the leader (#432),
 * behind the person or billet the request runs as. Set by the `ActAsUser` middleware, beside {@see ActingBillet}, so a
 * check that must answer for the real person and not only the identity taken on can ask here: counseling refuses to
 * let anyone file on, read the DCSs of, or change the records of the person really acting ({@see CounselingAccess}).
 * Scoped: it never outlives the request it was set on.
 */
#[Scoped]
class RealActor
{
    private ?User $user = null;

    public function set(User $user): void
    {
        $this->user = $user;
    }

    public function clear(): void
    {
        $this->user = null;
    }

    /** The real signed-in user while acting as someone, or null when the request runs as themselves. */
    public function get(): ?User
    {
        return $this->user;
    }
}
