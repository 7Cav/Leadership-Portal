<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use DateTimeInterface;

/**
 * Where a section's practice of one Cav week stands for a staff report. A practice can be claimed once its time has
 * passed (its hour is over), whether or not the section marked it held: a claim on one the section marked as not held
 * is flagged as a conflict on the report, never refused. Held is what the roster and the KPIs call held
 * ({@see PracticeTiming::held()}): confirmed as held, or over with a leader's mark on it.
 *
 * - {@see HELD}: held;
 * - {@see UPCOMING}: its hour has not passed yet this week, so it cannot be claimed yet;
 * - {@see UNMARKED}: its hour is over and nobody has marked it held yet;
 * - {@see NOT_HELD}: its hour is over and it was confirmed as not held;
 * - {@see NO_SLOT}: the section has no practice time set, so nothing says when it is over: it can be claimed only once
 *   it is held;
 * - {@see CANCELLED}: no practice that week, the week's practice cancelled (or, with no practice time, confirmed as not held).
 */
final class PracticeHeldState
{
    public const HELD = 'held';

    public const UPCOMING = 'upcoming';

    public const UNMARKED = 'unmarked';

    public const NOT_HELD = 'not_held';

    public const NO_SLOT = 'no_slot';

    public const CANCELLED = 'cancelled';

    /**
     * @param  ?SectionPractice  $practice  the section's practice that week, null when it has none
     * @param  bool  $marked  whether a leader's mark on that practice would hold it
     */
    public static function of(DateTimeInterface $week, ?SectionPractice $practice, ?SectionPracticeSlot $slot, bool $marked, DateTimeInterface $now): string
    {
        $timing = $practice === null ? null : PracticeTiming::state($practice, $slot, $now);
        $held = $timing !== null && PracticeTiming::held($timing, $marked);
        $end = PracticeTiming::hourEnd($week, $practice, $slot);

        // No hour that week: nothing says when it is over, so only a held one can be claimed.
        if ($end === null) {
            return match (true) {
                $held => self::HELD,
                $practice?->slot_cancelled === true || $timing === PracticeTiming::NOT_HELD => self::CANCELLED,
                default => self::NO_SLOT,
            };
        }

        return match (true) {
            $end->greaterThan($now) => self::UPCOMING,
            $held => self::HELD,
            $timing === PracticeTiming::NOT_HELD => self::NOT_HELD,
            default => self::UNMARKED,
        };
    }

    /** Whether a staff report may claim a practice in this state: its time has passed (or, with no hour, it is held). */
    public static function claimable(string $state): bool
    {
        return in_array($state, [self::HELD, self::UNMARKED, self::NOT_HELD], true);
    }

    /** Whether the section said the practice was not held: a staff report that claims it conflicts with the section. */
    public static function conflicts(string $state): bool
    {
        return $state === self::NOT_HELD || $state === self::CANCELLED;
    }
}
