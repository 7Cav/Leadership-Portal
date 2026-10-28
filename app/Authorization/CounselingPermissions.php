<?php

namespace App\Authorization;

use App\Models\CounselingRecord;
use App\Models\CounselingType;

/**
 * What one viewer may do with one trooper's counseling records (#381), worked out once by {@see CounselingAccess} so a
 * page listing many records asks the ScopeResolver once, not once per record.
 */
final readonly class CounselingPermissions
{
    /**
     * @param  array<string, ?int>  $issuingUnits  per type value, the unit the viewer would file through (null: none)
     * @param  list<int>  $writeScope  the viewer's write scope (AD-8), narrowed by an active view-as
     */
    public function __construct(
        public bool $seesDcs,
        public bool $seesLor,
        public bool $filesDcs,
        public bool $filesLor,
        public bool $admin,
        public bool $self,
        public array $issuingUnits,
        public array $writeScope,
    ) {}

    public function sees(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->seesDcs : $this->seesLor;
    }

    public function files(CounselingType $type): bool
    {
        return $type === CounselingType::Dcs ? $this->filesDcs : $this->filesLor;
    }

    /** The unit a new record of `$type` is filed through: the lowest command of the viewer's that reaches the trooper. */
    public function issuingUnit(CounselingType $type): ?int
    {
        return $this->issuingUnits[$type->value] ?? null;
    }

    /**
     * Whether the viewer may correct or delete `$record`: they must see it, and be an Admin or command the unit it was
     * filed through (that unit's subtree is in their write scope, so the issuer and the chain above them qualify).
     */
    public function changes(CounselingRecord $record): bool
    {
        if (! $this->sees($record->type) || $this->self && ! $this->admin) {
            return false;
        }

        return $this->admin || ($record->issuer_unit_id !== null && in_array((int) $record->issuer_unit_id, $this->writeScope, true));
    }
}
