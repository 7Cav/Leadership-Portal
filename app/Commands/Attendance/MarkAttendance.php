<?php

namespace App\Commands\Attendance;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Record a section's attendance for one Cav week (AD-14): every entry in `$marks` is written
 * against the section's practice for the week containing `$week` — any moment in that week; the
 * handler maps it to `week_start` through `WeekOf` (AD-11), never the caller. An empty batch is
 * valid: it records that the section's practice exists with nobody marked yet.
 *
 * Handled by {@see MarkAttendanceHandler}.
 */
final readonly class MarkAttendance
{
    /**
     * @param  list<AttendanceMark>  $marks
     *
     * @throws InvalidAttendanceMarkException when the same trooper appears twice — two entries
     *                                        for one row would make the outcome depend on order.
     */
    public function __construct(
        public int $sectionId,
        public DateTimeInterface $week,
        public array $marks,
    ) {
        $seen = [];

        foreach ($marks as $mark) {
            if (! $mark instanceof AttendanceMark) {
                throw new InvalidArgumentException('Every MarkAttendance entry must be an AttendanceMark, got '.get_debug_type($mark).'.');
            }

            if (isset($seen[$mark->trooperId])) {
                throw InvalidAttendanceMarkException::duplicateTrooper($mark->trooperId);
            }

            $seen[$mark->trooperId] = true;
        }
    }

    /**
     * @return list<int>
     */
    public function trooperIds(): array
    {
        return array_map(fn (AttendanceMark $mark) => $mark->trooperId, $this->marks);
    }
}
