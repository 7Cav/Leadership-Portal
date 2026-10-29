<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use Carbon\CarbonImmutable;

/**
 * Overturn a frozen LOR (S6, 2026-10-29): only the Regimental Executive Officer, signed in as themselves (never through
 * act as, by anyone: "only the RXO for now"), never on themselves, and only once its
 * 72-hour appeal window has passed ({@see CounselingPermissions::frozen()}); before that the issuer's chain corrects or
 * deletes it. A record the actor cannot see is not found; anything else refused is a 403. A departed trooper's LOR may
 * be overturned: it corrects the record and adds nothing to it.
 *
 * The record is never deleted. One audited action (`counseling.overturned`, AD-16) whose reason is required and is
 * stored on the record too, with when and by whom. The acting who-columns stay, as on every record, and stay empty.
 * From then on the LOR is shown as overturned and no longer counts toward the counseling rules.
 */
class OverturnCounselingRecordHandler
{
    public const REASON_MAX = 500;

    public function __construct(
        private readonly CounselingAccess $access,
        private readonly CounselingRecordRepository $records,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     * @throws ReasonRequiredException
     */
    public function handle(User $actor, OverturnCounselingRecord $command): CounselingRecord
    {
        $record = $this->records->find($command->recordId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $record->trooper);

        if (! $permissions->sees($record->type)) {
            throw new CounselingRecordNotFoundException;
        }

        if ($record->type !== CounselingType::Lor) {
            throw CounselingForbiddenException::onlyLor();
        }

        if ($record->isOverturned()) {
            throw CounselingForbiddenException::alreadyOverturned();
        }

        if (! CounselingPermissions::frozen($record)) {
            throw CounselingForbiddenException::notFrozen();
        }

        if ($permissions->self) {
            throw CounselingForbiddenException::overturnOwn();
        }

        // Checked here as well as in CounselingAccess: nobody acting as anyone overturns.
        if ($this->acting->admin() !== null || ! $permissions->overturns($record)) {
            throw CounselingForbiddenException::overturn();
        }

        $reason = trim((string) $command->reason);
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw InvalidCounselingRecordException::overturnReasonTooLong();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.overturned',
            reason: $reason,
            reasonRequired: true,
            mutation: fn () => $this->records->overturn(
                record: $record,
                at: CarbonImmutable::now(),
                by: $actor->id,
                byAdmin: $this->acting->adminActingAs($actor->id),
                asUnit: $this->acting->billetOf($actor->id),
                reason: $reason,
            ) ?? throw CounselingForbiddenException::alreadyOverturned(),
        );
    }
}
