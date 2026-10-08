<?php

namespace App\Commands\Attendance;

use DateTimeImmutable;

/**
 * Record a section's attendance for one Cav week (AD-14): every entry in `$marks` is written
 * against the section's practice for the week containing `$week` — any moment in that week; the
 * handler maps it to `week_start` through `WeekOf` (AD-11), never the caller. An empty batch is
 * valid: it records that the section's practice exists with nobody marked yet.
 *
 * A separate payload class (rather than plain `handle()` arguments, as the Units commands take)
 * because a batch of per-trooper entries carries invariants of its own — one entry per trooper —
 * that are checked here, before any handler runs. Handled by {@see MarkAttendanceHandler}.
 */
final readonly class MarkAttendance
{
    /** @var list<AttendanceMark> */
    public array $marks;

    /**
     * @throws InvalidAttendanceMarkException when the same trooper appears twice — two entries
     *                                        for one row would make the outcome depend on order.
     */
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        AttendanceMark ...$marks,
    ) {
        $seen = [];

        foreach ($marks as $mark) {
            if (isset($seen[$mark->trooperId])) {
                throw InvalidAttendanceMarkException::duplicateTrooper($mark->trooperId);
            }

            $seen[$mark->trooperId] = true;
        }

        $this->marks = array_values($marks);
    }

    /**
     * @return list<int>
     */
    public function trooperIds(): array
    {
        return array_map(fn (AttendanceMark $mark) => $mark->trooperId, $this->marks);
    }
}
