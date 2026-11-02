<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\SharedAccount;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * Removes a trooper's Steam or Epic ID from every game (#174), as one audited action (AD-16): every identity of theirs
 * with the same ID as their one on the command's game, entered as a kind of the same account, however each was made.
 * Scope-gated like a single removal (AD-8). When their identity on that game is not a Steam or Epic ID, nothing is
 * removed and nothing is logged. Past attendance is untouched.
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

        $account = $held === null ? null : SharedAccount::of((string) $held->id_kind);

        if ($account === null) {
            return new Collection;
        }

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'game_identity.account_removed',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($trooper, $held, $account) {
                $rows = $this->identities->unlinkAccount($trooper->id, $held->platform_id, $account);
                $rows->each(fn (TrooperGameIdentity $row) => $row->setRelation('trooper', $trooper));

                return new AuditedBatch($trooper, ...$rows->all());
            },
        );

        return new Collection($batch->members);
    }
}
