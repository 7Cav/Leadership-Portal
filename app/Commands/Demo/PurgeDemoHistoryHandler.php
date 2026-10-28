<?php

namespace App\Commands\Demo;

use App\Authorization\ScopeResolver;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\DemoHistoryCounts;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Deletes every demo row (#115) of a unit's whole subtree — `is_demo` practices, attendance and
 * reports — and nothing else, as one audited transaction (AD-16, action `demo_history.purged`,
 * target the chosen unit). Nothing to purge is a clean no-op that writes no audit entry, like a
 * no-op Admin-flag change.
 */
class PurgeDemoHistoryHandler
{
    public const ACTION = 'demo_history.purged';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly DemoHistoryRepository $demo,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws DemoHistoryForbiddenException when the actor is not an Admin, or the unit is outside
     *                                       their scope or does not exist.
     */
    public function handle(User $actor, PurgeDemoHistory $command): DemoHistoryPurged
    {
        if (! $this->scope->isAdmin($actor)) {
            throw DemoHistoryForbiddenException::notAdmin();
        }

        $root = $this->scope->writeScope($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($root === null) {
            throw DemoHistoryForbiddenException::outOfScope($command->unitId);
        }

        $subtree = $this->demo->subtreeUnits($root);
        $sectionIds = $subtree->filter(fn (Unit $unit) => $unit->type === UnitType::Section)->modelKeys();
        $unitIds = $subtree->modelKeys();

        if ($this->demo->count($sectionIds, $unitIds)->isEmpty()) {
            return new DemoHistoryPurged($root, new DemoHistoryCounts(0, 0, 0, 0));
        }

        $deleted = null;

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::ACTION,
            reason: 'Purge demo history for '.($root->callsign ?? $root->name),
            reasonRequired: false,
            mutation: function () use ($root, $sectionIds, $unitIds, &$deleted) {
                $deleted = $this->demo->purge($sectionIds, $unitIds);

                return new AuditedBatch($root);
            },
        );

        return new DemoHistoryPurged($root, $deleted);
    }
}
