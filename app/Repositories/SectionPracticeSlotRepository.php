<?php

namespace App\Repositories;

use App\Models\SectionPracticeSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `section_practice_slots` (#392): one weekly practice slot per section. Writes come only from the
 * audited `SetPracticeSlot` Command; each one is also kept in `section_practice_slot_changes` (#630).
 */
interface SectionPracticeSlotRepository
{
    public function forSection(int $sectionId): ?SectionPracticeSlot;

    /**
     * The slots of `$sectionIds`, keyed by section id; a section with none is absent. One query.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, SectionPracticeSlot>
     */
    public function forSections(array $sectionIds): Collection;

    /**
     * Every slot, with its section loaded.
     *
     * @return Collection<int, SectionPracticeSlot>
     */
    public function all(): Collection;

    /**
     * The slot in effect in the week starting `$weekStart`, as `[weekday, startMinute]`, or null when the section had
     * none that week. Read from the change history (#630), so a later change or removal never alters a past week.
     *
     * @return array{0: int, 1: int}|null
     */
    public function placingFor(int $sectionId, CarbonInterface $weekStart): ?array;

    /**
     * Set the section's slot, creating it or replacing the one it had. With `$effectiveFrom` (a Cav week's Saturday)
     * it starts that week and the weeks before keep `$previous` (`[weekday, startMinute]`, or null for none).
     *
     * @param  array{0: int, 1: int}|null  $previous
     */
    public function set(int $sectionId, int $weekday, int $startMinute, ?CarbonImmutable $effectiveFrom = null, ?array $previous = null): SectionPracticeSlot;

    /**
     * Remove the slot from `$effectiveFrom` (a Cav week's Saturday) on; the weeks before keep it. The model is returned
     * as it was, for the audit entry.
     */
    public function delete(SectionPracticeSlot $slot, ?CarbonImmutable $effectiveFrom = null): SectionPracticeSlot;
}
