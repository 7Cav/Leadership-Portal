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
use Illuminate\Support\Facades\Log;

/**
 * Share a draft with the trooper (#565), the explicit step nothing else takes. Refused, saying what is missing
 * ({@see CounselingNotReadyException}), until the draft has the infraction and its regulation, the notes of discussion,
 * the leader's plan of action, the Date of Letter and the leader's confirmation that the meeting took place; on an LOR
 * also a recommended consequence and the meeting invite ({@see SaveCounselingDraftHandler::missing()}).
 *
 * Sharing snapshots Section 1 as it stands ({@see CounselingFormParties::forRecord()}), locks the leader's fields, and
 * resolves who would decide an appeal: the commander of the unit one level above the one the record was started
 * through. Then, from the leader's own forum account, the trooper gets the After-Action PM
 * ({@see CounselingMessages::afterAction()}) and an LOR is copied to S1 and the battalion's oversight officer
 * ({@see CounselingMessages::s1Copy()}).
 *
 * One audited action (`counseling.shared`, AD-16). The trooper's PM is sent last inside it: if the forum does not take
 * it the share rolls back and the record stays a draft. The S1 copy goes only once the share has committed, so S1 is
 * never told of a share that did not happen and a retry never sends it twice; if it cannot go (nobody to send it to,
 * or the forum refuses it), the record stays shared and the result says the leader has to send it by hand.
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
    public function handle(User $actor, ShareCounselingRecord $command): SharedCounselingRecord
    {
        [$record, $permissions] = $this->loader->draft($actor, $command->recordId);

        // Sharing issues the record: whoever shares it must still be able to issue its type on the trooper.
        if (! $permissions->files($record->type)) {
            throw CounselingForbiddenException::file($record->type);
        }

        $missing = SaveCounselingDraftHandler::missing($record);
        if ($missing !== []) {
            throw new CounselingNotReadyException($missing);
        }

        $this->messenger->requireGrant($command->pm);
        $trooperId = $this->messenger->forumIdOf($record->trooper);
        $lor = $record->type === CounselingType::Lor;
        $snapshot = $this->parties->forRecord($record);
        $trooperName = trim($snapshot['trooper']['rank'].' '.$snapshot['trooper']['name']);

        $shared = $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.shared',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($record, $snapshot, $lor, $trooperId, $trooperName, $command) {
                $shared = $this->records->share($record, $snapshot, CarbonImmutable::now(), $lor ? $this->appealUnit($record) : null)
                    ?? throw CounselingForbiddenException::notDraft();

                $this->messenger->send($command->pm, [$trooperId], CounselingMessages::afterAction($shared, $trooperName));

                return $shared;
            },
        );

        if (! $lor) {
            return new SharedCounselingRecord($shared);
        }

        return new SharedCounselingRecord($shared, $this->copyToRecordKeepers($shared, $command, $trooperName, $snapshot));
    }

    /**
     * The LOR's copy to S1 and the oversight officer, after the share committed. What the leader still has to do when it
     * cannot go, or null when it went.
     *
     * @param  array{trooper: array{rank: string, name: string}, authority: array{rank: string, name: string}}  $snapshot
     */
    private function copyToRecordKeepers(CounselingRecord $shared, ShareCounselingRecord $command, string $trooperName, array $snapshot): ?string
    {
        $keepers = $this->messenger->recordKeepers($shared->trooper);

        if ($keepers === []) {
            Log::warning('Counseling: no S1 or oversight officer with a forum account to copy a shared LOR to.', ['record' => $shared->id]);

            return 'No S1 or oversight officer with a forum account was found, so the copy for record processing was not sent. Send the letter to S1 yourself.';
        }

        try {
            $issuerName = trim($snapshot['authority']['rank'].' '.$snapshot['authority']['name']);
            $this->messenger->send($command->pm, $keepers, CounselingMessages::s1Copy($shared, $trooperName, $issuerName));
        } catch (CounselingPmException $e) {
            report($e);

            return 'The letter was shared, but its copy to S1 and the oversight officer could not be sent ('.$e->getMessage().'). Send it to S1 yourself.';
        }

        return null;
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
