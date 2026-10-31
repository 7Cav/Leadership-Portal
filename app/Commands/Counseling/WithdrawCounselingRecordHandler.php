<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * Withdraw a shared record back to draft (#565), the correction path once it is shared: by its issuer, their chain or an
 * Admin, before it freezes and before the trooper asks to appeal ({@see CounselingPermissions::withdraws()}).
 * A reason is required. The snapshot and any response are dropped, so a re-share snapshots afresh and the trooper
 * answers again. The trooper is told by PM from the actor's own forum account ({@see CounselingMessages::withdrawn()}).
 *
 * One audited action (`counseling.withdrawn`, AD-16) whose reason is the withdrawal's; the PM is sent last inside it.
 */
class WithdrawCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingMessenger $messenger,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     * @throws CounselingPmException
     */
    public function handle(User $actor, WithdrawCounselingRecord $command): CounselingRecord
    {
        [$record, $permissions] = $this->loader->visible($actor, $command->recordId);

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        if ($record->isShared() && $record->isFrozen()) {
            throw CounselingForbiddenException::frozen($record->type);
        }

        if (! $permissions->withdraws($record)) {
            throw CounselingForbiddenException::withdraw();
        }

        $reason = CounselingRules::checkedReason($command->reason);
        $this->messenger->requireGrant($command->pm);
        $recipient = $this->messenger->forumIdOf($record->trooper);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.withdrawn',
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($record, $reason, $command, $recipient) {
                $withdrawn = $this->records->withdraw($record, CarbonImmutable::now(), $reason)
                    ?? throw CounselingForbiddenException::withdraw();

                $this->messenger->send($command->pm, [$recipient], CounselingMessages::withdrawn(
                    $withdrawn,
                    CounselingMessenger::nameOf($record->trooper),
                    $reason,
                ));

                return $withdrawn;
            },
        );
    }
}
