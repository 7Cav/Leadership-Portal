<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;
use App\Models\CounselingStatus;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * Send the meeting invite on a draft (#565): the wiki's "Meeting: …" PM ({@see CounselingMessages::invite()}) goes to the
 * trooper from the leader's own forum account, with the three Zulu windows they gave, and the draft becomes Invited, its
 * 72-hour meeting window running. Once per draft. Optional on a DCS (the trooper reached by voice within 24 hours, or a
 * positive development session); share needs it on an LOR.
 *
 * One audited action (`counseling.invited`, AD-16). The PM is sent last inside it, so a PM the forum does not take
 * rolls the invite back and the record is as it was ({@see CounselingPmException}).
 */
class SendCounselingInviteHandler
{
    public const WINDOW_MAX = 100;

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
    public function handle(User $actor, SendCounselingInvite $command): CounselingRecord
    {
        [$record] = $this->loader->draft($actor, $command->recordId);

        if ($record->status !== CounselingStatus::Draft) {
            throw CounselingForbiddenException::alreadyInvited();
        }

        $windows = array_values(array_map(fn ($window) => trim((string) $window), $command->windows));
        if (count($windows) !== 3 || in_array('', $windows, true) || max(array_map('mb_strlen', $windows)) > self::WINDOW_MAX) {
            throw InvalidCounselingRecordException::meetingWindows();
        }

        $this->messenger->requireGrant($command->pm);
        $recipient = $this->messenger->forumIdOf($record->trooper);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.invited',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($record, $windows, $command, $recipient) {
                $invited = $this->records->invite($record, $windows, CarbonImmutable::now())
                    ?? throw CounselingForbiddenException::notDraft();

                $this->messenger->send($command->pm, [$recipient], CounselingMessages::invite(
                    $invited,
                    CounselingMessenger::nameOf($record->trooper),
                    $windows,
                ));

                return $invited;
            },
        );
    }
}
