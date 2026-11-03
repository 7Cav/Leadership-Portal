<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Repositories\KeptGameIdentityRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Deletes a game identity kept on file for a Cav name with no trooper, as one audited action
 * (`kept_game_identity.removed`, reason optional); the log entry's target is the deleted row. Admins only, since the
 * name is in no unit whose scope could be checked. Deleting a row that is not there records nothing.
 */
class RemoveKeptGameIdentity
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly KeptGameIdentityRepository $kept,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @return bool true when a row was deleted.
     *
     * @throws GameIdentityForbiddenException when the actor is not an Admin.
     */
    public function handle(User $actor, int $id, ?string $reason = null): bool
    {
        if (! $this->scope->isAdmin($actor)) {
            throw GameIdentityForbiddenException::forTrooper(0);
        }

        $row = $this->kept->find($id);

        if ($row === null) {
            return false;
        }

        $this->audit->record(
            actorId: $actor->id,
            action: 'kept_game_identity.removed',
            reason: blank($reason) ? null : $reason,
            reasonRequired: false,
            mutation: function () use ($row) {
                $this->kept->delete($row);

                return $row;
            },
        );

        return true;
    }
}
