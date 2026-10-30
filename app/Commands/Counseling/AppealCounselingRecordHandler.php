<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use Carbon\CarbonImmutable;

/**
 * The trooper's appeal of an LOR, or comment or dispute on a DCS (the regiment's DCS form says a DCS is not subject to
 * appeal, so it is not called one there). Only the trooper, on their own record, signed in as themselves: never through
 * act as, by anyone, and never in a view-as preview ({@see CounselingPermissions::appeals()}). Only within the 72 hours
 * after the record was filed ({@see CounselingPermissions::frozen()}), once per record, and never on a trooper who has
 * left the Cav. A record the actor cannot see is not found; anything else refused is a 403.
 *
 * One audited action (`counseling.appealed`, AD-16) whose reason is the statement, which is stored on the record too,
 * with when and by whom. On an LOR made from the portal's form, the form's appeal answer becomes "do wish to appeal".
 * The issuer's chain sees the statement on the record, flagged as filed.
 */
class AppealCounselingRecordHandler
{
    public const STATEMENT_MAX = 2000;

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
    public function handle(User $actor, AppealCounselingRecord $command): CounselingRecord
    {
        $record = $this->records->find($command->recordId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $record->trooper);

        if (! $permissions->seesRecord($record)) {
            throw new CounselingRecordNotFoundException;
        }

        // Checked here as well as in CounselingAccess: nobody acting as anyone appeals.
        if ($this->acting->admin() !== null || ! $permissions->appealsOwn) {
            throw CounselingForbiddenException::appeal();
        }

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        if ($record->isAppealed()) {
            throw CounselingForbiddenException::alreadyAppealed($record->type);
        }

        if ($record->isOverturned() || CounselingPermissions::frozen($record) || ! $permissions->appeals($record)) {
            throw CounselingForbiddenException::appealClosed($record->type);
        }

        $statement = trim((string) $command->statement);
        if (mb_strlen($statement) > self::STATEMENT_MAX) {
            throw InvalidCounselingRecordException::appealStatementTooLong();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.appealed',
            reason: $statement,
            reasonRequired: true,
            mutation: fn () => $this->records->appeal(
                record: $record,
                at: CarbonImmutable::now(),
                by: $actor->id,
                statement: $statement,
            ) ?? throw CounselingForbiddenException::alreadyAppealed($record->type),
        );
    }
}
