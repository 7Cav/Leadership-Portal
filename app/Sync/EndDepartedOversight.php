<?php

namespace App\Sync;

use App\Models\TrooperStatus;
use App\Models\UnitOversight;
use App\Repositories\AuditedBatch;
use App\Repositories\AuditLogRepository;
use App\Repositories\UnitOversightRepository;

/**
 * After a roster sync: an overseer no longer on the active duty roster (ELOA, the Reservists, discharged or retired)
 * stops overseeing. Each removal is a System entry in the audit log (`unit.oversight_cleared`, no actor) targeting the
 * unit, as an Admin's clear does, so the unit filter finds it.
 */
class EndDepartedOversight
{
    public const REASON = 'No longer found on active duty roster (ELOA, discharge, etc.)';

    public function __construct(
        private readonly UnitOversightRepository $oversights,
        private readonly AuditLogRepository $auditLog,
    ) {}

    /** @return int how many were removed */
    public function handle(): int
    {
        $departed = UnitOversight::query()->with(['trooper', 'unit'])->get()
            ->filter(fn (UnitOversight $row) => $row->trooper === null || $row->trooper->status !== TrooperStatus::Combat);

        foreach ($departed as $row) {
            $this->auditLog->recordBatchWithMutation(null, 'unit.oversight_cleared', self::REASON, function () use ($row) {
                $cleared = $this->oversights->clear($row->unit_id);

                return new AuditedBatch($row->unit, $cleared ?? $row);
            });
        }

        return $departed->count();
    }
}
