<?php

namespace App\Repositories;

use App\Models\SectionPracticeSlot;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `section_practice_slots` (#392): one weekly practice slot per section. Writes come only from the
 * audited `SetPracticeSlot` Command.
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

    /** Set the section's slot, creating it or replacing the one it had. */
    public function set(int $sectionId, int $weekday, int $startMinute): SectionPracticeSlot;

    /** Remove the slot; the model is returned as it was, for the audit entry. */
    public function delete(SectionPracticeSlot $slot): SectionPracticeSlot;
}
