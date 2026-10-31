<?php

namespace App\Milpac;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The counseling panel of the trooper page (#381, #565): the DCSs and LORs the viewer may see, drafts first, then newest
 * first, and where they stand under the regulations. Read-only. Who sees which record, and may do what on it, is decided
 * by the controller through `Authorization\CounselingAccess` and handed in as closures; nothing the viewer may not see
 * is ever put in the result, so it cannot reach the page.
 *
 * The standing is {@see CounselingStanding}'s, measured between the shared records by their dates (2026-10-28): each
 * carries the flags it earned against the ones dated before it. A draft has no place in it until it is shared. The one
 * banner that runs on today is no favorable action, for 30 days after an LOR. For the start form, the flags a new record
 * would earn today (`forecast`), for each type the viewer may file and each kind.
 *
 * It is worked out only from the records the viewer may count (`$counts`): the ones they see, plus, for Platoon Leader
 * and up, a DCS past its 90 days, so a record's flags stay the same when an earlier DCS expires. The trooper counts only
 * what they see, so a flag never gives away a DCS hidden from them. An LOR the Regimental Executive Officer overturned
 * stays in the list, marked so, but counts toward none of it.
 */
class CounselingHistory
{
    public function __construct(private readonly CounselingRecordRepository $records) {}

    /**
     * @param  Closure(CounselingRecord): bool  $sees  whether the viewer sees a record (drafts included)
     * @param  Closure(CounselingRecord): bool  $counts  whether a shared record counts toward the standing the viewer is shown
     * @param  Closure(CounselingRecord): array<string, bool>  $can  what the viewer may do on a record
     * @param  list<CounselingType>  $files  the types the viewer may start, for the start form's forecast
     * @return array{records: list<array<string, mixed>>, warnings: list<array{key: string, message: string, until: string}>, forecast: array<string, array<string, list<array{from: string, flags: list<array{key: string, message: string}>}>>>}
     */
    public function for(Trooper $trooper, bool $readsAny, Closure $sees, Closure $counts, Closure $can, array $files = []): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $all = $readsAny ? $this->records->forTrooper($trooper->id) : collect();

        $counted = $all
            ->filter(fn (CounselingRecord $record) => $record->isShared() && $record->counseled_on !== null && ! $record->isOverturned() && $counts($record))
            ->values();
        $entries = $counted->map(fn (CounselingRecord $record) => CounselingStanding::fromRecord($record))->values()->all();
        $flags = CounselingStanding::flags($entries);
        $noFavorableAction = CounselingStanding::noFavorableAction($entries, $today);

        $forecast = [];
        foreach ($files as $type) {
            foreach (CounselingKind::cases() as $kind) {
                $forecast[$type->value][$kind->value] = CounselingStanding::forecast($entries, $type, $kind, $today);
            }
        }

        return [
            'records' => $all->filter($sees)->values()->map(fn (CounselingRecord $record) => [
                ...CounselingRecordView::of($record),
                // A record from before the portal asked, ruled as other conduct: said only where that changes a flag.
                'kindAssumed' => $record->kind === null && ! $record->isOverturned() && $record->isShared() && CounselingStanding::kindMatters($entries, $record->id),
                // A seeded demo record's source document has no file: the page says so instead of downloading.
                'document' => $record->hasSourceDocument()
                    ? ['name' => $record->document_name, 'size' => $record->document_size, 'demo' => CounselingDocuments::isDemo((string) $record->document_path)]
                    : null,
                'flags' => $flags[$record->id] ?? [],
                'can' => $can($record),
            ])->all(),
            'warnings' => $noFavorableAction === null ? [] : [$noFavorableAction],
            'forecast' => $forecast,
        ];
    }
}
