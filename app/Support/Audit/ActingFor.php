<?php

namespace App\Support\Audit;

use Illuminate\Container\Attributes\Scoped;

/**
 * Who is really acting on this request (#379). Set once per request by the `ActAsUser` middleware when an Admin is
 * acting as someone, so the {@see AuditEnvelope} records the Admin as actor and the person as `on_behalf_of`, whichever
 * Command wrote the entry. Scoped: it never outlives the request (or the queued job) it was set on.
 */
#[Scoped]
class ActingFor
{
    private ?int $admin = null;

    private ?int $user = null;

    public function set(int $adminId, int $userId): void
    {
        [$this->admin, $this->user] = [$adminId, $userId];
    }

    public function clear(): void
    {
        [$this->admin, $this->user] = [null, null];
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
        return $this->admin !== null && $actorId === $this->user ? $this->admin : null;
    }

    /**
     * Who an entry is recorded against: an action done as the person acted for is the Admin's, on their behalf;
     * anything else (the Admin's own start and stop, a job) is recorded as is.
     *
     * @return array{0: int, 1: ?int} the actor id and the on-behalf-of id
     */
    public function attribute(int $actorId): array
    {
        return $this->admin !== null && $actorId === $this->user ? [$this->admin, $this->user] : [$actorId, null];
    }
}
