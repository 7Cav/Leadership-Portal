<?php

namespace App\Milpac;

use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One counseling record as the pages show it (#565): where it stands in the wiki procedure and every deadline that
 * runs on it, worked out from the record on this read ({@see CounselingRecord::state()}), never stored. Read-only and
 * pure: what the viewer may do, Section 1 and the form's document are added by the controller, which decided that the
 * viewer sees the record at all. Instants are ISO 8601 UTC (`2026-10-31T19:00:00Z`), dates `Y-m-d`.
 */
final class CounselingRecordView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(CounselingRecord $record, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'id' => $record->id,
            'type' => $record->type->value,
            'typeLabel' => $record->type->label(),
            'kind' => $record->kind?->value,
            'kindLabel' => $record->kind?->label(),
            'state' => $record->state($now),
            'status' => $record->status->value,
            'version' => (int) $record->version,
            'source' => $record->source->value,
            'needsReview' => (bool) $record->needs_review,
            'hasSourceDocument' => $record->hasSourceDocument(),
            'issuer' => self::actor($record->issuedBy, $record->issuedByAdmin, $record->issuedAsUnit),
            'startedAt' => self::instant($record->created_at),
            'savedAt' => self::instant($record->updated_at),
            'date' => $record->counseled_on?->toDateString(),
            'reason' => $record->reason,
            'form' => $record->form_data ?? [],
            'meeting' => [
                'windows' => is_array($record->meeting['windows'] ?? null) ? array_values($record->meeting['windows']) : [],
                'invitedAt' => self::instant($record->invited_at),
                'closesAt' => self::instant($record->meetingClosesAt()),
                'confirmed' => (bool) $record->meeting_confirmed,
            ],
            'sharedAt' => self::instant($record->shared_at),
            'respondBy' => self::instant($record->respondBy()),
            'locksAt' => self::instant($record->freezesAt()),
            'frozen' => $record->isFrozen($now),
            'respondedAt' => self::instant($record->responded_at),
            'respondedBy' => $record->respondedBy?->username,
            'withdrawn' => $record->withdrawn_at === null ? null : [
                'at' => self::instant($record->withdrawn_at),
                'reason' => $record->withdraw_reason,
            ],
            'appeal' => $record->isAppealed() ? [
                'at' => self::instant($record->appealed_at),
                'reason' => $record->appeal_statement,
                'dueAt' => self::instant($record->appealDueAt()),
                'pending' => $record->isAppealPending($now),
                'decided' => $record->isAppealDecided() ? [
                    'at' => self::instant($record->appeal_decided_at),
                    'outcome' => $record->appeal_outcome?->value,
                    'outcomeLabel' => $record->appeal_outcome?->label(),
                    'reason' => $record->appeal_decision_reason,
                    'authority' => $record->appeal_authority,
                    'by' => self::actor($record->appealDecidedBy, $record->appealDecidedByAdmin, null),
                    'original' => $record->appeal_original,
                ] : null,
            ] : null,
            'overturned' => $record->isOverturned() ? [
                'date' => $record->overturned_at->toDateString(),
                'by' => self::actor($record->overturnedBy, $record->overturnedByAdmin, $record->overturnedAsUnit),
                'reason' => $record->overturn_reason,
            ] : null,
            'dcsPhase' => $record->dcsPhase($now->utc()),
            'inactiveOn' => $record->inactiveOn()?->toDateString(),
            'expiresOn' => $record->expiresOn()?->toDateString(),
        ];
    }

    /** `SGT Okafor.B`'s trooper card on the page: who the record is on. */
    public static function trooper(Trooper $trooper): array
    {
        return [
            'id' => $trooper->id,
            'name' => (string) $trooper->username,
            'rank' => (string) ($trooper->rank_short ?? ''),
            'billet' => (string) ($trooper->billet ?? ''),
        ];
    }

    /** Who did it, named as a record's issuer is: the person, `Hale.S for Okafor.B` when acted as, or the billet held. */
    public static function actor(?User $user, ?User $actingAdmin, ?Unit $asUnit): string
    {
        $name = $user?->username ?? 'Unknown';

        if ($actingAdmin !== null) {
            return ($actingAdmin->username ?? 'Unknown')." for {$name}";
        }

        if ($asUnit !== null) {
            return "{$name} acting as {$asUnit->billet()}";
        }

        return $name;
    }

    private static function instant(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
