<?php

namespace App\Milpac;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\User;
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
 * All of it is worked out only from the records the viewer sees, so a flag never gives away a DCS.
 */
class CounselingHistory
{
    public function __construct(private readonly CounselingRecordRepository $records) {}

    /**
     * @param  Closure(CounselingRecord): bool  $changes  whether the viewer may correct or delete a record
     * @param  list<CounselingType>  $files  the types the viewer may file, for the form's forecast
     * @return array{records: list<array<string, mixed>>, warnings: list<array{key: string, message: string, until: string}>, forecast: array<string, array<string, list<array{from: string, flags: list<array{key: string, message: string}>}>>>}
     */
    public function for(Trooper $trooper, bool $seesDcs, bool $seesLor, Closure $changes, array $files = []): array
    {
        $visible = ($seesDcs || $seesLor ? $this->records->forTrooper($trooper->id) : collect())
            ->filter(fn (CounselingRecord $record) => $record->type === CounselingType::Dcs ? $seesDcs : $seesLor)
            ->values();

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $entries = $visible->map(fn (CounselingRecord $record) => CounselingStanding::fromRecord($record))->all();
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
                'kindAssumed' => $record->kind === null && CounselingStanding::kindMatters($entries, $record->id),
                'date' => $record->counseled_on->toDateString(),
                'reason' => $record->reason,
                'issuer' => self::issuer($record),
                'document' => ['name' => $record->document_name, 'size' => $record->document_size],
                'driveStatus' => $record->drive_status->value,
                'flags' => $flags[$record->id] ?? [],
                'canChange' => $changes($record),
            ])->all(),
            'warnings' => $noFavorableAction === null ? [] : [$noFavorableAction],
            'forecast' => $forecast,
        ];
    }

    /** `SGT Okafor.B`, or `Hale.S for SGT Okafor.B` when an Admin filed it acting as them (#379). */
    private static function issuer(CounselingRecord $record): string
    {
        $name = self::userName($record->issuedBy);

        if ($record->issuedByAdmin !== null) {
            return self::userName($record->issuedByAdmin)." for {$name}";
        }

        if ($record->issuedAsUnit !== null) {
            return "{$name} acting as {$record->issuedAsUnit->billet()}";
        }

        return $name;
    }

    private static function userName(?User $user): string
    {
        return $user?->username ?? 'Unknown';
    }
}
