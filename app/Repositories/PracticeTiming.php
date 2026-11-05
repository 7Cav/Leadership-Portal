<?php

namespace App\Repositories;

use App\Models\AttendanceStatus;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Support\Time\PracticeWindow;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When a section's practice counts (#634). A practice counts as held only once it has happened or its leader confirmed
 * it: until its hour is over, and while nobody has confirmed it, its marks are left out of the SP numbers and nothing
 * calls it held. A confirmation decides on its own: confirmed held counts as held, confirmed not held never does.
 *
 * The practice is over when its hour ends: the captured window for a captured practice, else the week's one-off move,
 * else the slot in effect that week. A section with no slot, or a week whose practice was cancelled, has no hour, so
 * its practice is over only when the Cav week ends (Fri 2359Z), or once it is confirmed.
 */
final class PracticeTiming
{
    /** Not over and not confirmed: the marks wait. */
    public const PENDING = 'pending';

    /** Over and not confirmed: held when a leader's mark says so, as before. */
    public const OVER = 'over';

    /** Confirmed as held. */
    public const HELD = 'held';

    /** Confirmed as not held. */
    public const NOT_HELD = 'not_held';

    /** The moment the practice of `$practice`'s week is over: the end of its hour, or of the Cav week when it has none. */
    public static function endsAt(SectionPractice $practice, ?SectionPracticeSlot $slot): CarbonImmutable
    {
        return self::hourEnd($practice->week_start, $practice, $slot) ?? WeekOf::next(WeekOf::start($practice->week_start));
    }

    /**
     * When the week's practice hour ends, or null when it has none (no slot that week, or the week cancelled). Pass the
     * week's practice when it exists: a capture or a one-off move places the hour.
     */
    public static function hourEnd(DateTimeInterface $week, ?SectionPractice $practice, ?SectionPracticeSlot $slot): ?CarbonImmutable
    {
        $weekStart = WeekOf::start($week);

        if ($practice?->captured_at !== null && $practice->window_end !== null) {
            return CarbonImmutable::instance($practice->window_end)->utc();
        }

        $placing = $slot?->placingFor($weekStart);

        if ($practice?->slot_cancelled === true || $placing === null) {
            return null;
        }

        return $practice?->slot_moved_to !== null
            ? PracticeWindow::startingAt($practice->slot_moved_to)->end
            : PracticeWindow::inWeek($weekStart, $placing[0], $placing[1])->end;
    }

    public static function isOver(SectionPractice $practice, ?SectionPracticeSlot $slot, DateTimeInterface $now): bool
    {
        return self::endsAt($practice, $slot) <= CarbonImmutable::instance($now);
    }

    /** One of {@see PENDING}, {@see OVER}, {@see HELD} or {@see NOT_HELD}. */
    public static function state(SectionPractice $practice, ?SectionPracticeSlot $slot, DateTimeInterface $now): string
    {
        return match (true) {
            $practice->confirmed_at !== null => $practice->not_held ? self::NOT_HELD : self::HELD,
            self::isOver($practice, $slot, $now) => self::OVER,
            default => self::PENDING,
        };
    }

    /** Whether the practice is held, given whether any leader's mark on it would hold it. */
    public static function held(string $state, bool $marked): bool
    {
        return match ($state) {
            self::HELD => true,
            self::OVER => $marked,
            default => false,
        };
    }

    /**
     * The mark as it counts: attended and not attended say nothing yet about a practice still to come, nor about one
     * confirmed as not held. Leave, excused and make-ups (a practice attended elsewhere) count as they are.
     */
    public static function counted(string $state, ?AttendanceStatus $status): ?AttendanceStatus
    {
        if (($state === self::PENDING || $state === self::NOT_HELD) && in_array($status, [AttendanceStatus::Present, AttendanceStatus::Missed], true)) {
            return null;
        }

        return $status;
    }
}
