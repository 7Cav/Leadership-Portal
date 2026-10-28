<?php

namespace App\Milpac;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The counseling panel of the trooper page (#381): the DCSs and LORs on file that the viewer may see, newest first, and
 * the warnings the regulations' 90-day rules call for. Read-only. Who may see which type, and change which record, is
 * decided by the controller through `Authorization\CounselingAccess` and handed in; nothing the viewer may not see is
 * ever read into the result, so it cannot reach the page.
 *
 * The 90-day window counts back from today (UTC, AD-11's clock): a record dated within the last 90 days counts, an older
 * one is kept and listed but no longer counts toward a warning. The rules, from the regulations:
 *
 *  - a second DCS within 90 days of one: the next step is an LOR;
 *  - a second LOR within 90 days: the trooper may be removed as Section Leader or ASL;
 *  - a third LOR within 90 days: an Article 15.
 *
 * Warnings are worked out only from the records the viewer sees, so the warning itself never gives away a DCS.
 */
class CounselingHistory
{
    public const WINDOW_DAYS = 90;

    public function __construct(private readonly CounselingRecordRepository $records) {}

    /**
     * @param  Closure(CounselingRecord): bool  $changes  whether the viewer may correct or delete a record
     * @return array{records: list<array<string, mixed>>, counts: array{dcs: int, lor: int}, warnings: list<array{key: string, message: string}>, windowDays: int}
     */
    public function for(Trooper $trooper, bool $seesDcs, bool $seesLor, Closure $changes): array
    {
        $visible = ($seesDcs || $seesLor ? $this->records->forTrooper($trooper->id) : collect())
            ->filter(fn (CounselingRecord $record) => $record->type === CounselingType::Dcs ? $seesDcs : $seesLor)
            ->values();

        $since = self::windowStart();
        $counting = $visible->filter(fn (CounselingRecord $record) => $record->counseled_on->greaterThanOrEqualTo($since));
        $counts = [
            'dcs' => $counting->filter(fn (CounselingRecord $record) => $record->type === CounselingType::Dcs)->count(),
            'lor' => $counting->filter(fn (CounselingRecord $record) => $record->type === CounselingType::Lor)->count(),
        ];

        return [
            'records' => $visible->map(fn (CounselingRecord $record) => [
                'id' => $record->id,
                'type' => $record->type->value,
                'typeLabel' => $record->type->label(),
                'date' => $record->counseled_on->toDateString(),
                'reason' => $record->reason,
                'issuer' => self::issuer($record),
                'document' => ['name' => $record->document_name, 'size' => $record->document_size],
                'driveStatus' => $record->drive_status->value,
                'counts' => $record->counseled_on->greaterThanOrEqualTo($since),
                'countsUntil' => $record->counseled_on->addDays(self::WINDOW_DAYS)->toDateString(),
                'canChange' => $changes($record),
            ])->all(),
            'counts' => $counts,
            'warnings' => self::warnings($counts),
            'windowDays' => self::WINDOW_DAYS,
        ];
    }

    /** The first day still inside the window: today minus 90 days. */
    public static function windowStart(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay()->subDays(self::WINDOW_DAYS);
    }

    /**
     * @param  array{dcs: int, lor: int}  $counts
     * @return list<array{key: string, message: string}>
     */
    public static function warnings(array $counts): array
    {
        $warnings = [];

        if ($counts['lor'] >= 3) {
            $warnings[] = ['key' => 'article-15', 'message' => "{$counts['lor']} Letters of Reprimand in 90 days. The regulations call for an Article 15."];
        } elseif ($counts['lor'] === 2) {
            $warnings[] = ['key' => 'removal', 'message' => '2 Letters of Reprimand in 90 days. The trooper may be removed as Section Leader or Assistant Section Leader.'];
        }

        if ($counts['dcs'] >= 2) {
            $warnings[] = ['key' => 'lor-due', 'message' => "{$counts['dcs']} Developmental Counseling Statements in 90 days. A repeat within 90 days of a DCS calls for a Letter of Reprimand."];
        }

        return $warnings;
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
