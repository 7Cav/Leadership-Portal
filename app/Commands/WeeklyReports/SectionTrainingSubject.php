<?php

namespace App\Commands\WeeklyReports;

use App\Models\PlatoonTrainingTopic;

/**
 * How a section's training subject combines with the topic its platoon leader set for the week.
 * Precedence: an enforced platoon topic, then the section's own entry, then a platoon topic that is
 * not enforced. (Last week's subject is only ever a suggestion the leader accepts on the page; it
 * is never applied here.)
 */
final class SectionTrainingSubject
{
    /** The subject the section's report shows for the week. */
    public static function effective(?PlatoonTrainingTopic $topic, ?string $own): ?string
    {
        if ($topic?->enforced) {
            return $topic->subject;
        }

        return $own ?? $topic?->subject;
    }

    /**
     * What a draft save stores as the section's own entry. Under an enforced topic the section
     * cannot change it: a different subject is refused and the stored entry is kept as it was, so
     * it applies again if the platoon leader lifts the lock. A subject equal to a topic that is not
     * enforced is not the section's own: it is left unset, so the section follows the topic if the
     * platoon leader changes it.
     *
     * @throws TrainingSubjectEnforcedException
     */
    public static function forDraft(int $sectionId, ?PlatoonTrainingTopic $topic, ?string $submitted, ?string $stored): ?string
    {
        if ($topic?->enforced) {
            self::refuseOverride($sectionId, $topic, $submitted);

            return $stored;
        }

        return $topic !== null && $submitted === $topic->subject ? null : $submitted;
    }

    /**
     * What a send freezes: the enforced topic when there is one (a different subject is refused),
     * otherwise the subject the leader confirmed.
     *
     * @throws TrainingSubjectEnforcedException
     */
    public static function forSend(int $sectionId, ?PlatoonTrainingTopic $topic, ?string $submitted): ?string
    {
        if ($topic?->enforced) {
            self::refuseOverride($sectionId, $topic, $submitted);

            return $topic->subject;
        }

        return $submitted;
    }

    private static function refuseOverride(int $sectionId, PlatoonTrainingTopic $topic, ?string $submitted): void
    {
        if ($submitted !== null && $submitted !== $topic->subject) {
            throw new TrainingSubjectEnforcedException($sectionId);
        }
    }
}
