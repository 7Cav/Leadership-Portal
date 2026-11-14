<?php

namespace App\Tutorial;

/**
 * Which hands-on tutorial the person runs: the basics (roll call, the weekly report, the staff report and the roster),
 * or fixing the problems that hold up a roll call or a report (a make-up, a guest, a blocked report, a missing one).
 * Each builds its own practice copy ({@see PracticeWorld}) and has its own steps ({@see TutorialSteps}).
 */
enum TutorialTrack: string
{
    case Basics = 'basics';
    case FixProblems = 'fix-problems';

    /** Whether `$persona` can run this track: fixing problems needs a unit to lead (or Admin). */
    public function offeredTo(TutorialPersona $persona): bool
    {
        return $this === self::Basics || $persona->leadsUnit();
    }

    /** The name in the tutorial bar. */
    public function title(): string
    {
        return match ($this) {
            self::Basics => 'Tutorial',
            self::FixProblems => 'Fixing problems tutorial',
        };
    }
}
