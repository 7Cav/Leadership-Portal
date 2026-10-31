<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * Start a DCS or LOR on a trooper as a draft (#565). Who may is unchanged from filing (#381, {@see CounselingAccess}):
 * a DCS from Section Leader up, an LOR from platoon command up, an Admin either; never on a trooper who has left the Cav
 * or on oneself. An unknown trooper, or one the actor cannot see, is not found. The draft names the unit it was started
 * through, which decides who else works on it. One audited action (`counseling.started`, AD-16).
 */
class StartCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingAccess $access,
        private readonly TrooperRepository $troopers,
        private readonly CounselingRecordRepository $records,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     */
    public function handle(User $actor, StartCounselingRecord $command): CounselingRecord
    {
        $trooper = $this->troopers->find($command->trooperId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $trooper);

        if (! $permissions->seesLor) {
            throw new CounselingRecordNotFoundException;
        }

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($trooper);
        }

        if (! $permissions->files($command->type)) {
            throw CounselingForbiddenException::file($command->type);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.started',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->records->startDraft(
                trooperId: $trooper->id,
                type: $command->type,
                kind: $command->kind,
                issuedBy: $actor->id,
                issuedByAdmin: $this->acting->adminActingAs($actor->id),
                issuedAsUnit: $this->acting->billetOf($actor->id),
                issuerUnitId: $permissions->issuingUnit($command->type),
            ),
        );
    }
}
