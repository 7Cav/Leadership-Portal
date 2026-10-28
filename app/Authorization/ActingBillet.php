<?php

namespace App\Authorization;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;

/**
 * The billet an Admin holds on this request while acting as one (#379 follow-up): a unit's leader, with no person
 * behind it. Set by the `ActAsUser` middleware for the stand-in it runs the request as; {@see ScopeResolver} then
 * counts that unit as the only one the stand-in commands, so their read and write scope is the unit's subtree and
 * nothing else. Scoped: it never outlives the request it was set on.
 */
#[Scoped]
class ActingBillet
{
    private ?int $userId = null;

    private ?Unit $unit = null;

    public function hold(User $standIn, Unit $unit): void
    {
        [$this->userId, $this->unit] = [$standIn->id, $unit];
    }

    public function clear(): void
    {
        [$this->userId, $this->unit] = [null, null];
    }

    /** The unit `$user` leads on this request through a held billet, or null. Never for an Admin acting as themselves. */
    public function of(User $user): ?Unit
    {
        return ! $user->is_admin && $user->id === $this->userId ? $this->unit : null;
    }
}
