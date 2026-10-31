<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * Share a draft with the trooper (#565), the explicit step nothing else takes. Refused, saying what is missing
 * ({@see CounselingNotReadyException}), until the draft has the infraction and its regulation, the notes of discussion,
 * the leader's plan of action, the Date of Letter and the leader's confirmation that the meeting took place; on an LOR
 * also a recommended consequence and the meeting invite ({@see SaveCounselingDraftHandler::missing()}).
 *
 * Sharing snapshots Section 1 as it stands ({@see CounselingFormParties::forRecord()}), locks the leader's fields, and
 * resolves who would decide an appeal: the commander of the unit one level above the one the record was started
 * through. Then, from the leader's own forum account, an LOR is copied to S1 and the battalion's oversight officer
 * ({@see CounselingMessages::s1Copy()}) and the trooper gets the After-Action PM ({@see CounselingMessages::afterAction()}).
 *
 * One audited action (`counseling.shared`, AD-16), the PMs sent last inside it: a PM the forum does not take rolls the
 * share back, and the record stays a draft. The trooper's PM goes last, so they are never told about a share that did
 * not happen.
 */
class ShareCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingFormParties $parties,
        private readonly CounselingMessenger $messenger,
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws CounselingNotReadyException
     * @throws CounselingPmException
     */
    public function handle(User $actor, ShareCounselingRecord $command): CounselingRecord
    {
        [$record] = $this->loader->draft($actor, $command->recordId);

        $missing = SaveCounselingDraftHandler::missing($record);
        if ($missing !== []) {
            throw new CounselingNotReadyException($missing);
        }

        $this->messenger->requireGrant($command->pm);
        $trooperId = $this->messenger->forumIdOf($record->trooper);
        $lor = $record->type === CounselingType::Lor;
        $keepers = $lor ? $this->messenger->recordKeepers($record->trooper) : [];
        $snapshot = $this->parties->forRecord($record);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.shared',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($record, $snapshot, $lor, $keepers, $trooperId, $command) {
                $shared = $this->records->share($record, $snapshot, CarbonImmutable::now(), $lor ? $this->appealUnit($record) : null)
                    ?? throw CounselingForbiddenException::notDraft();

                $trooperName = trim($snapshot['trooper']['rank'].' '.$snapshot['trooper']['name']);

                if ($lor) {
                    $issuerName = trim($snapshot['authority']['rank'].' '.$snapshot['authority']['name']);
                    $this->messenger->send($command->pm, $keepers, CounselingMessages::s1Copy($shared, $trooperName, $issuerName));
                }

                $this->messenger->send($command->pm, [$trooperId], CounselingMessages::afterAction($shared, $trooperName));

                return $shared;
            },
        );
    }

    /**
     * The unit whose commander hears an appeal: the parent of the unit the LOR was issued through, or, for one started
     * by an Admin with no issuing unit, the parent of the trooper's lowest unit at platoon level or above.
     */
    private function appealUnit(CounselingRecord $record): ?int
    {
        $issuing = $record->issuer_unit_id === null ? null : $this->units->find((int) $record->issuer_unit_id);
        if ($issuing !== null) {
            return $issuing->parent_id === null ? null : (int) $issuing->parent_id;
        }

        $section = $record->trooper->primary_section_id === null ? null : $this->units->find((int) $record->trooper->primary_section_id);
        $unit = $section;
        while ($unit !== null && ! ($unit->type->isLine() && $unit->type->rank() <= UnitType::Platoon->rank())) {
            $unit = $unit->parent_id === null ? null : $this->units->find((int) $unit->parent_id);
        }

        return $unit instanceof Unit && $unit->parent_id !== null ? (int) $unit->parent_id : null;
    }
}
