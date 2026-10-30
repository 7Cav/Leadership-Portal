<?php

namespace App\Milpac;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The counseling panel of the trooper page (#381): the DCSs and LORs on file that the viewer may see, newest first, and
 * where they stand under the regulations. Read-only. Who may see which type, and change which record, is decided by the
 * controller through `Authorization\CounselingAccess` and handed in; nothing the viewer may not see is ever read into the
 * result, so it cannot reach the page.
 *
 * The standing is {@see CounselingStanding}'s, measured between the records by their dates (2026-10-28): each record
 * carries the flags it earned against the ones dated before it, and they stay with it as today moves on. The one
 * banner that runs on today is no favorable action, for 30 days after an LOR. For the filing form, the flags a new
 * record would earn, by the date it is given (`forecast`), for each type the viewer may file and each kind.
 *
 * All of it is worked out only from the records the viewer sees, so a flag never gives away a DCS. An LOR the Regimental
 * Executive Officer overturned (S6, 2026-10-29) stays in the list, marked so, but counts toward none of it: it carries no
 * flags, earns none for the records after it, and starts no no-favorable-action period.
 *
 * A DCS expires 90 days after it was counseled ({@see CounselingRecord::isExpired()}). Only an Admin sees it in the list,
 * marked expired with the date. It still counts for the records dated within its windows (a repeat DCS within 90 days
 * of it, a second missed practice or roll call within 30), so a record's flags stay the same when an earlier DCS
 * expires. Every window is 90 days or shorter, so an expired DCS never reaches a record dated today or later: it adds
 * nothing to the warnings, and to the forecast only for a date given back within its window.
 */
class CounselingHistory
{
    public function __construct(private readonly CounselingRecordRepository $records) {}

    /**
     * @param  Closure(CounselingRecord): bool  $changes  whether the viewer may correct or delete a record
     * @param  list<CounselingType>  $files  the types the viewer may file, for the form's forecast
     * @param  (Closure(CounselingRecord): bool)|null  $overturns  whether the viewer may overturn a record (none if null)
     * @param  (Closure(CounselingRecord): bool)|null  $frozen  whether a record is past its appeal window (none if null)
     * @param  bool  $seesExpired  whether the viewer sees an expired DCS (Admins only)
     * @param  (Closure(CounselingRecord): bool)|null  $appeals  whether the viewer, the trooper, may appeal a record (none if null)
     * @return array{records: list<array<string, mixed>>, warnings: list<array{key: string, message: string, until: string}>, forecast: array<string, array<string, list<array{from: string, flags: list<array{key: string, message: string}>}>>>}
     */
    public function for(Trooper $trooper, bool $seesDcs, bool $seesLor, Closure $changes, array $files = [], ?Closure $overturns = null, ?Closure $frozen = null, bool $seesExpired = false, ?Closure $appeals = null): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $counted = ($seesDcs || $seesLor ? $this->records->forTrooper($trooper->id) : collect())
            ->filter(fn (CounselingRecord $record) => $record->type === CounselingType::Dcs ? $seesDcs : $seesLor)
            ->values();
        $visible = $counted
            ->filter(fn (CounselingRecord $record) => $seesExpired || ! $record->isExpired($today))
            ->values();

        $entries = $counted
            ->reject(fn (CounselingRecord $record) => $record->isOverturned())
            ->map(fn (CounselingRecord $record) => CounselingStanding::fromRecord($record))
            ->values()
            ->all();
        $flags = CounselingStanding::flags($entries);
        $noFavorableAction = CounselingStanding::noFavorableAction($entries, $today);

        $forecast = [];
        foreach ($files as $type) {
            foreach (CounselingKind::cases() as $kind) {
                $forecast[$type->value][$kind->value] = CounselingStanding::forecast($entries, $type, $kind, $today);
            }
        }

        return [
            'records' => $visible->map(fn (CounselingRecord $record) => [
                'id' => $record->id,
                'type' => $record->type->value,
                'typeLabel' => $record->type->label(),
                'kind' => $record->kind?->value,
                'kindLabel' => $record->kind?->label(),
                // A record from before the portal asked, ruled as other conduct: said only where that changes a flag.
                'kindAssumed' => $record->kind === null && ! $record->isOverturned() && CounselingStanding::kindMatters($entries, $record->id),
                'date' => $record->counseled_on->toDateString(),
                'reason' => $record->reason,
                'issuer' => self::issuer($record),
                // A seeded demo record's document has no file: the page says so instead of downloading.
                'document' => ['name' => $record->document_name, 'size' => $record->document_size, 'demo' => CounselingDocuments::isDemo($record->document_path)],
                'driveStatus' => $record->drive_status->value,
                // The document was made in the portal from the regiment's form, not uploaded.
                'generated' => $record->form_data !== null,
                'flags' => $flags[$record->id] ?? [],
                'canChange' => $changes($record),
                'frozen' => $frozen !== null && $frozen($record),
                'overturned' => $record->isOverturned() ? [
                    'date' => $record->overturned_at->toDateString(),
                    'by' => self::actor($record->overturnedBy, $record->overturnedByAdmin, $record->overturnedAsUnit),
                    'reason' => $record->overturn_reason,
                ] : null,
                'expired' => $record->isExpired($today) ? ['date' => $record->expiresOn()->toDateString()] : null,
                'canOverturn' => $overturns !== null && $overturns($record),
                // When it freezes (72 hours after it was filed), as an ISO 8601 UTC instant.
                'freezesAt' => $record->freezesAt()?->utc()->format('Y-m-d\TH:i:s\Z'),
                // The trooper's appeal (LOR) or comment or dispute (DCS), seen by whoever sees the record.
                'appeal' => $record->isAppealed() ? [
                    'at' => $record->appealed_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'statement' => $record->appeal_statement,
                ] : null,
                'canAppeal' => $appeals !== null && $appeals($record),
            ])->all(),
            'warnings' => $noFavorableAction === null ? [] : [$noFavorableAction],
            'forecast' => $forecast,
        ];
    }

    /** `SGT Okafor.B`, or `Hale.S for SGT Okafor.B` when an Admin filed it acting as them (#379). */
    private static function issuer(CounselingRecord $record): string
    {
        return self::actor($record->issuedBy, $record->issuedByAdmin, $record->issuedAsUnit);
    }

    /** Who did it, named as on a filing: the person, `Hale.S for Okafor.B` when acted as, or the billet held. */
    private static function actor(?User $user, ?User $actingAdmin, ?Unit $asUnit): string
    {
        $name = self::userName($user);

        if ($actingAdmin !== null) {
            return self::userName($actingAdmin)." for {$name}";
        }

        if ($asUnit !== null) {
            return "{$name} acting as {$asUnit->billet()}";
        }

        return $name;
    }

    private static function userName(?User $user): string
    {
        return $user?->username ?? 'Unknown';
    }
}
