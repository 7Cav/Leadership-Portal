<?php

namespace App\Commands\Practices;

use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Support\Time\PracticeWindow;
use Carbon\CarbonImmutable;

/**
 * Reads when a section's practice happens in a given week (#392): its slot placed in the week, or that week's one-off
 * move, or nothing when the week was cancelled or the section has no slot. A captured practice answers the window it
 * was captured for, so a later slot change never rewrites it. Read-only; the writes are {@see SetPracticeSlot} and
 * {@see ChangePracticeWeek}.
 */
class PracticeSchedule
{
    public function __construct(
        private readonly SectionPracticeSlotRepository $slots,
        private readonly SectionPracticeRepository $practices,
    ) {}

    public function slotOf(int $sectionId): ?SectionPracticeSlot
    {
        return $this->slots->forSection($sectionId);
    }

    /**
     * The week's practice window, or null when the section has no slot or the week's practice is cancelled. Pass the
     * week's practice when the caller has it, to save the read.
     */
    public function windowFor(int $sectionId, CarbonImmutable $weekStart, ?SectionPractice $practice = null, ?SectionPracticeSlot $slot = null): ?PracticeWindow
    {
        $practice ??= $this->practices->find($sectionId, $weekStart);

        if ($practice?->window_start !== null && $practice->window_end !== null && $practice->captured_at !== null) {
            return PracticeWindow::between($practice->window_start, $practice->window_end);
        }

        $slot ??= $this->slots->forSection($sectionId);

        if ($slot === null || $practice?->slot_cancelled === true) {
            return null;
        }

        return $practice?->slot_moved_to !== null
            ? PracticeWindow::startingAt($practice->slot_moved_to)
            : PracticeWindow::inWeek($weekStart, $slot->weekday, $slot->start_minute);
    }

    /**
     * The roll call's `practiceSlot` prop: the slot and the week's practice, or null when the section has no slot.
     *
     * @return array{weekday: int, startMinute: int, week: array{start: string|null, end: string|null, cancelled: bool, moved: bool, captured: bool}}|null
     */
    public function props(int $sectionId, CarbonImmutable $weekStart, ?SectionPractice $practice): ?array
    {
        $slot = $this->slots->forSection($sectionId);

        if ($slot === null) {
            return null;
        }

        $practice ??= $this->practices->find($sectionId, $weekStart);
        $window = $this->windowFor($sectionId, $weekStart, $practice, $slot);

        return [
            'weekday' => $slot->weekday,
            'startMinute' => $slot->start_minute,
            'week' => [
                'start' => $window?->start->toIso8601ZuluString(),
                'end' => $window?->end->toIso8601ZuluString(),
                'cancelled' => $practice?->slot_cancelled === true,
                'moved' => $practice?->slot_moved_to !== null,
                'captured' => $practice?->captured_at !== null,
            ],
        ];
    }

    /**
     * The slots of `$sectionIds` as `{weekday, startMinute}`, keyed by section id, for the roster and Home rows.
     *
     * @param  list<int>  $sectionIds
     * @return array<int, array{weekday: int, startMinute: int}>
     */
    public function slotsOf(array $sectionIds): array
    {
        return $this->slots->forSections($sectionIds)
            ->map(fn (SectionPracticeSlot $slot) => ['weekday' => $slot->weekday, 'startMinute' => $slot->start_minute])
            ->all();
    }
}
