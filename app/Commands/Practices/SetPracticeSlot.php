<?php

namespace App\Commands\Practices;

use App\Authorization\ScopeResolver;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\PracticeWindow;
use InvalidArgumentException;

/**
 * Set or clear a section's weekly practice slot (#392): a weekday and a start time in Zulu, the practice lasting one
 * hour. Gated by the section's write scope (AD-8): its leaders, the command above them and Admins; a staff grant only
 * reads, and a view-as preview is refused before it gets here. Audited (AD-16); a change that changes nothing is not
 * logged. A new slot applies from the next practice not yet captured: a captured week keeps the window it was read for
 * (`section_practices.window_start`/`window_end`).
 */
class SetPracticeSlot
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeSlotRepository $slots,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * `$weekday` or `$startMinute` null clears the slot.
     *
     * @throws PracticeSlotForbiddenException when the section is outside the actor's write scope (or does not exist).
     * @throws InvalidPracticeSlotException when the unit is not a section or the slot is out of range.
     */
    public function handle(User $actor, int $sectionId, ?int $weekday, ?int $startMinute): void
    {
        // Scope first, so a unit outside it is refused whether or not it exists (AD-8).
        $section = $this->scope->writeScope($actor)->contains($sectionId) ? $this->units->find($sectionId) : null;

        if ($section === null) {
            throw new PracticeSlotForbiddenException;
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidPracticeSlotException::notASection($section);
        }

        $current = $this->slots->forSection($sectionId);

        if ($weekday === null || $startMinute === null) {
            if ($current !== null) {
                $this->audit->record($actor->id, 'practice_slot.cleared', null, false, fn () => $this->slots->delete($current));
            }

            return;
        }

        try {
            PracticeWindow::requireSlot($weekday, $startMinute);
        } catch (InvalidArgumentException) {
            throw InvalidPracticeSlotException::outOfRange();
        }

        if ($current?->weekday === $weekday && $current->start_minute === $startMinute) {
            return;
        }

        $this->audit->record($actor->id, 'practice_slot.set', null, false, fn () => $this->slots->set($sectionId, $weekday, $startMinute));
    }
}
