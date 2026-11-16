<?php

namespace App\Repositories;

use App\Models\PracticeSlotConfirmation;
use Carbon\CarbonInterface;

/**
 * Sole DB access for `practice_slot_confirmations` (16NOV26): a platoon leader's answer for a section and a clock
 * change. Written only by the audited `ConfirmClockChange` Command.
 */
interface PracticeSlotConfirmationRepository
{
    /** The answer for `$sectionId` and the clock change at `$changeAt`, or null when none was given. */
    public function find(int $sectionId, CarbonInterface $changeAt): ?PracticeSlotConfirmation;

    /** Records the answer; a second one for the same section and change keeps the first. */
    public function record(int $sectionId, CarbonInterface $changeAt, int $weekday, int $startMinute, bool $moved, int $userId): PracticeSlotConfirmation;

    /** Keeps the time zone the person's browser reports; written only when it changed. */
    public function setTimezone(int $userId, string $timezone): void;

    /** The time zone kept for `$userId`, or null. */
    public function timezoneOf(int $userId): ?string;

    /**
     * Every time zone kept for anyone, once each: a quick look whether any clock change is near before a page asks per
     * section.
     *
     * @return list<string>
     */
    public function knownTimezones(): array;
}
