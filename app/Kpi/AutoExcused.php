<?php

namespace App\Kpi;

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The weeks a trooper is excused without anyone marking it (2026-10-30), the one definition the KPI service, the roll
 * call and the roster read:
 *
 * - **recruit**: the Cav week of their join date (`troopers.tis`, Cav's `joinDate`);
 * - **transfer**: the Cav week they were placed into a section, from the placement history. A placement from before
 *   the history began is not a transfer, so nobody is excused by the history's first rows.
 *
 * An automatic Excused works like leave: the week leaves the RC and SP rates, neither met nor missed, and a report
 * counts it as accounted. It is only a default, never stored: whatever a leader records for the week wins, fact by
 * fact. A signature they set by hand (anything but unsigned) counts for RC, and a practice mark set by hand, or an
 * attended one, counts for SP. Only an empty fact, or an automatic miss, is excused.
 */
final class AutoExcused
{
    public const RECRUIT = 'recruit';

    public const TRANSFER = 'transfer';

    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /**
     * The automatically excused weeks of these troopers among the `$weeks` Cav weeks from `$firstWeek`, with why. A
     * trooper with none is absent. One query.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, array<string, string>> trooper id => `week_start` date => {@see RECRUIT} or {@see TRANSFER}
     *
     * @throws InvalidArgumentException when `$firstWeek` is not a Saturday 00:00 UTC or `$weeks` is below 1.
     */
    public function forTroopersOverWeeks(iterable $trooperIds, CarbonInterface $firstWeek, int $weeks): array
    {
        if ($weeks < 1) {
            throw new InvalidArgumentException("The span must cover at least one Cav week, got {$weeks}.");
        }

        $from = WeekOf::requireStart($firstWeek);
        $until = WeekOf::next($from, $weeks);
        $ids = collect($trooperIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $excused = [];

        foreach ($this->placements->firstWeekEventsBetween($ids, $from, $until) as $trooperId => $events) {
            foreach ($events as $event) {
                $week = WeekOf::start($event['at'])->toDateString();
                // The join week wins over a placement in the same week: it says more about why.
                $excused[$trooperId][$week] = ($excused[$trooperId][$week] ?? null) === self::RECRUIT ? self::RECRUIT : $event['kind'];
            }
        }

        return $excused;
    }

    /**
     * {@see forTroopersOverWeeks()} for one week: trooper id => why, for the troopers excused that week only.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, string>
     */
    public function forTroopers(iterable $trooperIds, CarbonInterface $weekStart): array
    {
        $date = WeekOf::requireStart($weekStart)->toDateString();

        return array_filter(array_map(
            fn (array $weeks) => $weeks[$date] ?? null,
            $this->forTroopersOverWeeks($trooperIds, $weekStart, 1),
        ));
    }

    /** An automatic Excused takes the signature half of the week while nothing was signed (an unsigned or missing row). */
    public static function excusesSignature(?RollcallSignature $rollcall): bool
    {
        return $rollcall === null || $rollcall === RollcallSignature::Unsigned;
    }

    /**
     * An automatic Excused takes the practice half of the week while no leader marked it: an empty mark, or an
     * automatic one that is not attended (a capture's miss). A leader's mark of any status, and any attended mark, wins.
     */
    public static function excusesPractice(?AttendanceStatus $status, ?AttendanceSource $source): bool
    {
        return $status === null || ($source === AttendanceSource::Auto && ! $status->attended() && $status !== AttendanceStatus::Loa);
    }
}
