<?php

namespace App\Commands\AdminBillets;

use App\Authorization\ScopeResolver;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\User;
use App\Repositories\AdminBilletRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Approve or reject a billet as an Admin billet (decided by the S6, 2026-10-28). Approving confirms the title and group
 * Cav shows now, so the billet's holder is an Admin while they serve in it and the billet keeps those names in a group
 * that may make an Admin ({@see ScopeResolver::isAdmin()}); a billet whose group is not one of those is never approved.
 * Rejecting leaves it a plain billet until a name changes and it comes back as pending. Rejecting an approved billet is
 * how an Admin revokes it.
 *
 * Only an Admin decides, by flag or by an approved billet, so an approved billet Admin may confirm others. Nobody
 * decides on the billet they hold themselves: the decider's own trooper's Cav position is refused, so a billet Admin
 * whose billet is renamed cannot confirm the new name for themselves, and needs another Admin. A personnel action, so
 * audited with a required reason (AD-16) as `admin_billet.approved` or `admin_billet.rejected`, targeting the row.
 */
class DecideAdminBilletHandler
{
    public const APPROVED = 'admin_billet.approved';

    public const REJECTED = 'admin_billet.rejected';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly AdminBilletRepository $billets,
        private readonly TrooperRepository $troopers,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws AdminBilletForbiddenException when the actor is not an Admin
     * @throws InvalidAdminBilletDecisionException when the decision is refused
     * @throws ReasonRequiredException when the reason is blank
     */
    public function handle(User $actor, DecideAdminBillet $command): AdminBillet
    {
        if (! $this->scope->isAdmin($actor)) {
            throw new AdminBilletForbiddenException;
        }

        $billet = $this->billets->find($command->billetId) ?? throw InvalidAdminBilletDecisionException::gone();
        $own = $actor->cav_id === null ? null : $this->troopers->byCavId([(int) $actor->cav_id])->get((int) $actor->cav_id)?->cav_position_id;

        if ($own !== null && (int) $own === $billet->cav_position_id) {
            throw InvalidAdminBilletDecisionException::ownBillet();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: $command->approve ? self::APPROVED : self::REJECTED,
            reason: $command->reason,
            reasonRequired: true,
            mutation: function () use ($command, $actor) {
                // Read again under a lock: a sync or another Admin may have changed it since the page loaded.
                $billet = $this->billets->lockForDecision($command->billetId) ?? throw InvalidAdminBilletDecisionException::gone();

                if ($command->approve) {
                    if ($billet->status === AdminBilletStatus::Approved) {
                        throw InvalidAdminBilletDecisionException::alreadyApproved();
                    }

                    if ($billet->proposed_title === null || $billet->proposed_group_title === null) {
                        throw InvalidAdminBilletDecisionException::namesUnknown();
                    }

                    // Moved out of the Admin billet groups: approving would confirm names that make nobody an Admin.
                    if (! AdminBillet::isAdminGroup($billet->proposed_group_title)) {
                        throw InvalidAdminBilletDecisionException::notAdminGroup();
                    }
                } elseif ($billet->status === AdminBilletStatus::Rejected) {
                    throw InvalidAdminBilletDecisionException::alreadyRejected();
                }

                return $this->billets->decide(
                    $billet,
                    $command->approve ? AdminBilletStatus::Approved : AdminBilletStatus::Rejected,
                    $actor->id,
                    $this->acting->adminActingAs($actor->id),
                    $this->acting->billetOf($actor->id),
                );
            },
        );
    }
}
