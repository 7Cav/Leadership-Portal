<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use DateTimeInterface;

/**
 * Whether a section's practice of one Cav week can be claimed on a staff report: only one that was held can be ticked.
 * Held is what the roster and the KPIs call held ({@see PracticeTiming::held()}): confirmed as held, or over with a
 * leader's mark on it. Otherwise it says why not, so the picker can say it:
 *
 * - {@see HELD}: held, so it can be ticked;
 * - {@see UPCOMING}: its hour has not come yet this week;
 * - {@see UNMARKED}: its hour is over and nobody has marked it held yet;
 * - {@see NOT_HELD}: confirmed as not held, or the week's practice was cancelled;
 * - {@see NO_SLOT}: the section has no practice time set, so there is no practice to pick.
 */
final class PracticeHeldState
{
    public const HELD = 'held';

    public const UPCOMING = 'upcoming';

    public const UNMARKED = 'unmarked';

    public const NOT_HELD = 'not_held';

    public const NO_SLOT = 'no_slot';

    /**
     * @param  ?SectionPractice  $practice  the section's practice that week, null when it has none
     * @param  bool  $marked  whether a leader's mark on that practice would hold it
     */
    public static function of(DateTimeInterface $week, ?SectionPractice $practice, ?SectionPracticeSlot $slot, bool $marked, DateTimeInterface $now): string
    {
        if ($practice !== null) {
            $state = PracticeTiming::state($practice, $slot, $now);

            if (PracticeTiming::held($state, $marked)) {
                return self::HELD;
            }

            if ($state === PracticeTiming::NOT_HELD || $practice->slot_cancelled === true) {
                return self::NOT_HELD;
            }
        }

        $end = PracticeTiming::hourEnd($week, $practice, $slot);

        if ($end === null) {
            return self::NO_SLOT;
        }

        return $end->greaterThan($now) ? self::UPCOMING : self::UNMARKED;
    }
}
