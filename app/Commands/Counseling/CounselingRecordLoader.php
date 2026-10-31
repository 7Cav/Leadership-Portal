<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;

/**
 * Loads a counseling record for one step of the procedure (#565) with what the actor may do on it, the first thing every
 * handler here does: a record that does not exist, or that the actor does not see, is not found, exactly alike.
 */
class CounselingRecordLoader
{
    public function __construct(
        private readonly CounselingRecordRepository $records,
        private readonly CounselingAccess $access,
    ) {}

    /**
     * @return array{0: CounselingRecord, 1: CounselingPermissions}
     *
     * @throws CounselingRecordNotFoundException
     */
    public function visible(User $actor, int $recordId): array
    {
        $record = $this->records->find($recordId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $record->trooper);

        if (! $permissions->seesRecord($record)) {
            throw new CounselingRecordNotFoundException;
        }

        return [$record, $permissions];
    }

    /**
     * A draft the actor may work on.
     *
     * @return array{0: CounselingRecord, 1: CounselingPermissions}
     *
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     */
    public function draft(User $actor, int $recordId): array
    {
        [$record, $permissions] = $this->visible($actor, $recordId);

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        if (! $record->isDraft()) {
            throw CounselingForbiddenException::notDraft();
        }

        if (! $permissions->editsDraft($record)) {
            throw CounselingForbiddenException::draft();
        }

        return [$record, $permissions];
    }
}
