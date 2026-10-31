<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * The trooper responds to a shared DCS or LOR (#565), once: their plan of action and comments, and on an LOR whether
 * they DO or DO NOT wish to appeal, with a reason when they do. Only the trooper, on their own record, signed in as
 * themselves (never through act as or a view-as preview), before {@see CounselingRecord::respondBy()}: 24 hours after an
 * LOR was shared, or until a DCS locks ({@see CounselingPermissions::responds()}). After that the record shows No
 * response and stands as written.
 *
 * "DO appeal" queues the LOR for the commander one level above the issuing unit (resolved when it was shared) and sends
 * them a PM from the trooper's own forum account ({@see CounselingMessages::appealRequested()}). With no such commander
 * serving the appeal is refused, so it is never asked of nobody.
 *
 * One audited action (`counseling.responded`, AD-16), whose reason is the appeal's when there is one; the PM is sent
 * last inside it.
 */
class RespondToCounselingRecordHandler
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
    public function handle(User $actor, RespondToCounselingRecord $command): CounselingRecord
    {
        [$record, $permissions] = $this->loader->visible($actor, $command->recordId);

        if (! $permissions->appealsOwn) {
            throw CounselingForbiddenException::respond();
        }

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        if ($record->isResponded()) {
            throw CounselingForbiddenException::alreadyResponded();
        }

        if (! $permissions->responds($record)) {
            throw CounselingForbiddenException::responseClosed($record->type);
        }

        // Judged by the record's own type, whatever type the request assumed.
        $response = $command->response->forType($record->type);
        $response->check($record->type);

        $authority = null;
        if ($response->appeals()) {
            $authority = $this->messenger->commanderOf($record->appeal_unit_id) ?? throw InvalidCounselingRecordException::noAppealAuthority();
            $this->messenger->requireGrant($command->pm);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.responded',
            reason: $response->appeals() ? $response->appealReason : null,
            reasonRequired: false,
            mutation: function () use ($record, $actor, $response, $authority, $command) {
                $responded = $this->records->respond(
                    record: $record,
                    at: CarbonImmutable::now(),
                    by: $actor->id,
                    trooperFields: $response->toArray(),
                    appealStatement: $response->appeals() ? $response->appealReason : null,
                ) ?? throw CounselingForbiddenException::alreadyResponded();

                if ($authority !== null) {
                    $snapshot = $record->snapshot ?? [];
                    $this->messenger->send($command->pm, [$this->messenger->forumIdOf($authority)], CounselingMessages::appealRequested(
                        $responded,
                        CounselingMessenger::nameOf($record->trooper),
                        trim(($snapshot['authority']['rank'] ?? '').' '.($snapshot['authority']['name'] ?? $record->issuedBy?->username ?? '')),
                        $response->appealReason,
                    ));
                }

                return $responded;
            },
        );
    }
}
