<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingPermissions;
use App\Models\AppealOutcome;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * The appeal authority decides an appealed LOR (#565): the commander of the unit one level above the issuing unit,
 * while the appeal is pending ({@see CounselingPermissions::decidesAppeal()}), never on their own record. HAVE AMENDED
 * changes the recommended consequence (the consequences, the removal and the special punishments), keeping it as first
 * written in the record's history; HAVE NOT AMENDED leaves it. Either needs a reason. Their rank, name and billet then
 * are snapshotted into the outcome.
 *
 * The decision is final: it freezes the LOR, and goes by PM from the authority's own forum account to the trooper, the
 * issuer and S1 ({@see CounselingMessages::appealDecided()}). One audited action (`counseling.appeal_decided`, AD-16),
 * whose reason is the decision's; the PM is sent last inside it.
 */
class DecideCounselingAppealHandler
{
    public const REASON_MAX = 2000;

    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingFormParties $parties,
        private readonly CounselingMessenger $messenger,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     * @throws CounselingPmException
     */
    public function handle(User $actor, DecideCounselingAppeal $command): CounselingRecord
    {
        [$record, $permissions] = $this->loader->visible($actor, $command->recordId);

        if (! $permissions->decidesAppeal($record)) {
            throw CounselingForbiddenException::decide();
        }

        $reason = CounselingRules::checkedReason($command->reason, self::REASON_MAX);

        $amended = null;
        if ($command->outcome === AppealOutcome::Amended) {
            $form = CounselingForm::fromInput([...($record->form_data ?? []), ...($command->amended?->toArray() ?? [])], CounselingType::Lor);
            $form->check();
            $missing = array_intersect_key($form->missing(CounselingType::Lor), array_flip(['consequences', 'removal']));
            if ($missing !== []) {
                throw new InvalidCounselingRecordException('form.'.array_key_first($missing), reset($missing));
            }
            $amended = array_intersect_key($form->toArray(), array_flip(['consequences', 'removal', 'special_punishments']));
        }

        $this->messenger->requireGrant($command->pm);
        $recipients = [
            $this->messenger->forumIdOf($record->trooper),
            $this->messenger->forumIdOfUser($record->issuedBy),
            ...$this->messenger->s1()->all(),
        ];
        $authority = $this->parties->of($actor);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.appeal_decided',
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($record, $actor, $command, $reason, $authority, $amended, $recipients) {
                $decided = $this->records->decideAppeal(
                    record: $record,
                    at: CarbonImmutable::now(),
                    by: $actor->id,
                    byAdmin: $this->acting->adminActingAs($actor->id),
                    outcome: $command->outcome,
                    reason: $reason,
                    authority: $authority,
                    amended: $amended,
                ) ?? throw CounselingForbiddenException::decide();

                $this->messenger->send($command->pm, $recipients, CounselingMessages::appealDecided(
                    $decided,
                    CounselingMessenger::nameOf($record->trooper),
                    $command->outcome,
                    $reason,
                ));

                return $decided;
            },
        );
    }
}
