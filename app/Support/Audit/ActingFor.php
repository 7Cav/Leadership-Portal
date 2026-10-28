<?php

namespace App\Support\Audit;

use Illuminate\Container\Attributes\Scoped;

/**
 * Who is really acting on this request (#379). Set once per request by the `ActAsUser` middleware when an Admin is
 * acting, so the {@see AuditEnvelope} records it whichever Command wrote the entry:
 *
 *  - acting as a **person**, an action done as that person is the Admin's, with the person as `on_behalf_of`;
 *  - acting as a **billet** (a unit's leader, no person behind it), an action is the Admin's own, with the unit as
 *    `on_behalf_of_unit`.
 *
 * Scoped: it never outlives the request (or the queued job) it was set on.
 */
#[Scoped]
class ActingFor
{
    private ?int $admin = null;

    private ?int $user = null;

    private ?int $unit = null;

    /** Acting as a person: their actions are recorded as the Admin's, on their behalf. */
    public function set(int $adminId, int $userId): void
    {
        [$this->admin, $this->user, $this->unit] = [$adminId, $userId, null];
    }

    /** Acting as a billet: the Admin's own actions are recorded as done in that unit's leader's place. */
    public function setBillet(int $adminId, int $unitId): void
    {
        [$this->admin, $this->user, $this->unit] = [$adminId, null, $unitId];
    }

    public function clear(): void
    {
        [$this->admin, $this->user, $this->unit] = [null, null, null];
    }

    /** The real Admin's id while acting, or null. */
    public function admin(): ?int
    {
        return $this->admin;
    }

    /**
     * The Admin acting as `$actorId` on this request, or null when `$actorId` is acting in their own name. For the
     * records that name who did something (a report's sender, a guest's adder, a topic's setter), which keep the person
     * and name the Admin beside them.
     */
    public function adminActingAs(int $actorId): ?int
    {
        return $this->admin !== null && $this->user !== null && $actorId === $this->user ? $this->admin : null;
    }

    /** The unit whose billet `$actorId` holds on this request, or null when they act in their own right. */
    public function billetOf(int $actorId): ?int
    {
        return $this->unit !== null && $actorId === $this->admin ? $this->unit : null;
    }

    /**
     * Who an entry is recorded against: an action done as the person acted for is the Admin's, on their behalf; an
     * action done while holding a billet is the Admin's, in that unit's place; anything else is recorded as is.
     *
     * @return array{0: int, 1: ?int, 2: ?int} the actor id, the on-behalf-of user id and the on-behalf-of unit id
     */
    public function attribute(int $actorId): array
    {
        $admin = $this->adminActingAs($actorId);

        return $admin !== null ? [$admin, $actorId, null] : [$actorId, null, $this->billetOf($actorId)];
    }
}
