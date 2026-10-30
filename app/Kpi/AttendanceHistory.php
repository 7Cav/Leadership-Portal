<?php

namespace App\Kpi;

use App\Loa\LoaCoverage;
use App\Models\AttendanceStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;

/**
 * One trooper's attendance week by week across every unit they were in (decided 2026-10-27): "when you transfer to a
 * new area, the new leadership can see your history". For the milpac: each week's section, rollcall signature,
 * practice mark and LOA. The same counting marks the KPIs read (AD-21), so a week shown here is the week the KPIs
 * count, wherever it was recorded. Past weekly reports are not part of it: they stay with the unit that sent them.
 */
final class AttendanceHistory
{
    /** As many Cav weeks as the roster's heatmap. */
    public const WEEKS = 20;

    public function __construct(
        private readonly AttendanceRepository $attendance,
        private readonly TrooperPlacementRepository $placements,
        private readonly UnitRepository $units,
        private readonly LoaCoverage $loa,
        private readonly AutoExcused $autoExcused,
    ) {}

    /**
     * The {@see WEEKS} Cav weeks ending with `$weekStart`, oldest first. `section` is the section they were placed in
     * that week (null when in none: not placed yet, on HQ staff or in a group); a week with no practice row there has
     * no `rollcall` or `status`. `loa` is true for a week leave covers or a practice marked `loa`. `excused` is why the
     * week was excused automatically ({@see AutoExcused}: `recruit` or `transfer`), or null.
     *
     * @return list<array{week: string, section: ?array{id: int, label: string}, rollcall: ?string, status: ?string, source: ?string, held: bool, loa: bool, excused: ?string}>
     */
    public function forTrooper(Trooper $trooper, CarbonInterface $weekStart): array
    {
        $last = WeekOf::requireStart($weekStart);
        $first = $last->subWeeks(self::WEEKS - 1);

        $marks = $this->attendance->countingMarksOf([$trooper->id], $first, $last, includeDeparted: true)
            ->keyBy(fn (CountingMark $mark) => $mark->weekStart->toDateString());
        $placed = $this->placements->sectionsByWeek([$trooper->id], $first, $last)->weeks[$trooper->id] ?? [];
        $covers = $this->loa->forTroopersOverWeeks([$trooper->id], $first, self::WEEKS)[$trooper->id] ?? [];
        $excused = $this->autoExcused->forTroopersOverWeeks([$trooper->id], $first, self::WEEKS)[$trooper->id] ?? [];
        $labels = [];

        return array_map(function (int $offset) use ($first, $marks, $placed, $covers, $excused, &$labels) {
            $week = $first->addWeeks($offset)->toDateString();
            /** @var CountingMark|null $mark */
            $mark = $marks->get($week);
            $sectionId = $mark?->sectionId ?? (isset($placed[$week]) ? end($placed[$week]) : null);

            return [
                'week' => $week,
                'section' => $sectionId === null ? null : ['id' => $sectionId, 'label' => $labels[$sectionId] ??= $this->label($sectionId)],
                'rollcall' => $mark?->rollcall->value,
                'status' => $mark?->status?->value,
                'source' => $mark?->source?->value,
                'held' => $mark->held ?? false,
                'loa' => (isset($covers[$week]) && $covers[$week]->isCovered()) || $mark?->status === AttendanceStatus::Loa,
                'excused' => $excused[$week] ?? null,
            ];
        }, range(0, self::WEEKS - 1));
    }

    private function label(int $unitId): string
    {
        $unit = $this->units->find($unitId);

        return $unit instanceof Unit ? ($unit->callsign ?? $unit->name) : 'a unit since removed';
    }
}
