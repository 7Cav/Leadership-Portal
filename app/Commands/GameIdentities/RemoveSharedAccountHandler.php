<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\IdentitySource;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * Removes the rows a shared Steam or Epic account made for a trooper (#174), as one audited action (AD-16): every
 * identity of theirs with the same ID as their one on the command's game and source from_account. A row with that ID
 * that was typed, or came from a rollcall, stays. Scope-gated like a single removal (AD-8). When their identity on that
 * game did not come from an account, nothing is removed and nothing is logged. Past attendance is untouched.
 */
class RemoveSharedAccountHandler
{
    use AuthorizesGameIdentityChange;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * The rows removed.
     *
     * @return Collection<int, TrooperGameIdentity>
     *
     * @throws GameIdentityForbiddenException when the trooper is outside the actor's scope.
     */
    public function handle(User $actor, RemoveSharedAccount $command): Collection
    {
        $trooper = $this->authorizedTrooper($actor, $command->trooperId, $this->scope, $this->troopers);

        $held = $this->identities->forTrooper($trooper->id)->firstWhere('game_id', $command->game->id);

        if ($held === null || $held->source !== IdentitySource::FromAccount) {
            return new Collection;
        }

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'game_identity.account_removed',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($trooper, $held) {
                $rows = $this->identities->unlinkAccount($trooper->id, $held->platform_id);
                $rows->each(fn (TrooperGameIdentity $row) => $row->setRelation('trooper', $trooper));

                return new AuditedBatch($trooper, ...$rows->all());
            },
        );

        return new Collection($batch->members);
    }
}
